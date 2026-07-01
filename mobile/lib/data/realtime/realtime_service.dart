/// Realtime WS service for the mobile app — hand-rolled Pusher client.
///
/// Laravel Reverb speaks the Pusher wire protocol over a plain WebSocket.
/// We implement just enough of that protocol (connect → subscribe →
/// receive events) using `web_socket_channel` so we don't depend on a
/// Pusher SDK that assumes a clustered Pusher Cloud broker.
///
/// One connection per app session. Auto-reconnect with exponential
/// backoff. Channels:
///   - `client.{client_key}.folder.{folder_id|root}`  (file events)
///   - `folder.{user_id}.{folder_id|root}`            (folder events)
library;

import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:web_socket_channel/web_socket_channel.dart';
import 'realtime_event.dart';

enum RealtimeState { disconnected, connecting, connected, reconnecting }

class RealtimeConfig {
  const RealtimeConfig({
    required this.wsHost,
    required this.wsPort,
    required this.wsScheme,
    required this.appKey,
    required this.authEndpoint,
    required this.token,
  });

  final String wsHost;
  final int wsPort;
  final String wsScheme;
  final String appKey;
  final String authEndpoint;
  final String token;
}

class RealtimeService {
  WebSocketChannel? _channel;
  RealtimeConfig? _config;

  final _events = StreamController<RealtimeEvent>.broadcast();
  final _state = StreamController<RealtimeState>.broadcast();

  RealtimeState _currentState = RealtimeState.disconnected;
  int _backoffMs = 1000;
  bool _shouldReconnect = false;
  String? _clientKey;
  String? _userId;
  String? _currentFolderId;
  Timer? _reconnectTimer;

  Stream<RealtimeEvent> get events => _events.stream;
  Stream<RealtimeState> get state => _state.stream;
  RealtimeState get currentState => _currentState;

  static const _maxBackoffMs = 30000;
  static const _backoffJitter = 0.2;

  Future<void> connect({
    required RealtimeConfig config,
    required String clientKey,
    required String userId,
    String? currentFolderId,
  }) async {
    await disconnect();

    _config = config;
    _clientKey = clientKey;
    _userId = userId;
    _currentFolderId = currentFolderId;
    _shouldReconnect = true;
    _backoffMs = 1000;

    _setState(RealtimeState.connecting);
    try {
      await _openConnection();
      _setState(RealtimeState.connected);
      _backoffMs = 1000;
      await _subscribeAll();
    } catch (_) {
      _setState(RealtimeState.reconnecting);
      _scheduleReconnect();
    }
  }

  Future<void> setCurrentFolder(String? folderId) async {
    if (_currentFolderId == folderId || _channel == null) return;
    await _unsubscribeAll();
    _currentFolderId = folderId;
    await _subscribeAll();
  }

  Future<void> disconnect() async {
    _shouldReconnect = false;
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    if (_channel != null) {
      try {
        await _channel!.sink.close();
      } catch (_) {
        // ignore
      }
      _channel = null;
    }
    _setState(RealtimeState.disconnected);
  }

  // ── Wire protocol internals ──────────────────────────────────

  Uri _wsUri() {
    final scheme = _config!.wsScheme == 'wss' ? 'wss' : 'ws';
    return Uri(
      scheme: scheme,
      host: _config!.wsHost,
      port: _config!.wsPort,
      path: '/app/${_config!.appKey}',
    );
  }

  Future<void> _openConnection() async {
    final uri = _wsUri();
    _channel = WebSocketChannel.connect(uri);
    // Wait for the connection to be ready.
    await _channel!.ready;

    // Pusher requires `pusher:connection_established` event after WS
    // open. We don't need to send anything for the connection itself —
    // Reverb treats the WS upgrade as the connect event. But we must
    // subscribe explicitly below.
    _channel!.stream.listen(
      _onWsMessage,
      onError: (_) {
        _setState(RealtimeState.reconnecting);
        _scheduleReconnect();
      },
      onDone: () {
        if (_shouldReconnect) {
          _setState(RealtimeState.reconnecting);
          _scheduleReconnect();
        } else {
          _setState(RealtimeState.disconnected);
        }
      },
      cancelOnError: true,
    );
  }

  void _onWsMessage(Object? raw) {
    if (raw is! String) return;
    Map<String, dynamic> msg;
    try {
      msg = jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return;
    }
    final event = msg['event'] as String?;
    if (event == null) return;
    final channel = msg['channel'] as String?;
    final data = msg['data'];

    // Pusher control events.
    if (event == 'pusher:error') {
      _setState(RealtimeState.reconnecting);
      _scheduleReconnect();
      return;
    }
    if (event == 'pusher:connection_established' ||
        event == 'pusher:subscription_succeeded') {
      // Connection or subscription confirmed — nothing to do, state
      // already updated on subscribe call.
      return;
    }

    // Domain event. Pusher routes broadcasted events to `App\Events\...`
    // channel-prefixed event names — extract the payload from `data`
    // (always JSON string per Pusher spec).
    final parsed = parseRealtimePayload(event, data);
    if (parsed != null && !_events.isClosed) {
      _events.add(parsed);
    }
    // Reference channel so linter doesn't drop it.
    void _ = channel;
  }

  Future<Map<String, dynamic>> _authorizeChannel(String channel) async {
    final url = Uri.parse(_config!.authEndpoint);
    final res = await http.post(
      url,
      headers: {
        'Authorization': 'Bearer ${_config!.token}',
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: {
        'socket_id': 'placeholder',
        'channel_name': channel,
      },
    );
    if (res.statusCode != 200) {
      throw StateError('Channel auth failed (${res.statusCode}): ${res.body}');
    }
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    return body;
  }

  Future<void> _subscribeAll() async {
    final clientKey = _clientKey;
    final userId = _userId;
    final channel = _channel;
    if (clientKey == null || userId == null || channel == null) return;

    final fileChannel = 'client.$clientKey.folder.${_currentFolderId ?? 'root'}';
    final folderChannel = 'folder.$userId.${_currentFolderId ?? 'root'}';

    await _subscribe(fileChannel);
    await _subscribe(folderChannel);
  }

  Future<void> _subscribe(String channelName) async {
    final auth = await _authorizeChannel(channelName);
    final msg = jsonEncode({
      'event': 'pusher:subscribe',
      'data': {
        'auth': auth['auth'],
        'channel': channelName,
        if (auth['channel_data'] != null)
          'channel_data': auth['channel_data'],
      },
    });
    _channel?.sink.add(msg);
  }

  Future<void> _unsubscribeAll() async {
    final clientKey = _clientKey;
    final userId = _userId;
    final channel = _channel;
    if (clientKey == null || userId == null || channel == null) return;

    try {
      channel.sink.add(jsonEncode({
        'event': 'pusher:unsubscribe',
        'data': {'channel': 'client.$clientKey.folder.${_currentFolderId ?? 'root'}'},
      }));
    } catch (_) {}
    try {
      channel.sink.add(jsonEncode({
        'event': 'pusher:unsubscribe',
        'data': {'channel': 'folder.$userId.${_currentFolderId ?? 'root'}'},
      }));
    } catch (_) {}
  }

  void _setState(RealtimeState s) {
    if (_currentState == s) return;
    _currentState = s;
    if (!_state.isClosed) _state.add(s);
  }

  void _scheduleReconnect() {
    if (!_shouldReconnect) return;
    _reconnectTimer?.cancel();
    final jitter = (_backoffMs * _backoffJitter *
            (DateTime.now().millisecondsSinceEpoch % 100) / 100)
        .toInt();
    final delay = Duration(milliseconds: _backoffMs + jitter);
    _backoffMs = (_backoffMs * 2).clamp(1000, _maxBackoffMs);
    _reconnectTimer = Timer(delay, () async {
      if (!_shouldReconnect || _config == null) return;
      _setState(RealtimeState.reconnecting);
      try {
        if (_channel != null) {
          await _channel!.sink.close();
        }
      } catch (_) {}
      try {
        await _openConnection();
        _setState(RealtimeState.connected);
        await _subscribeAll();
      } catch (_) {
        _scheduleReconnect();
      }
    });
  }
}