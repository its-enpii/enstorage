/// Realtime WS service for the mobile app — hand-rolled Pusher client.
///
/// Laravel Reverb speaks the Pusher wire protocol over a plain WebSocket.
/// We implement just enough of that protocol (connect → subscribe →
/// receive events) using `web_socket_channel` so we don't depend on a
/// Pusher SDK that assumes a clustered Pusher Cloud broker.
///
/// One connection per app session. Auto-reconnect with exponential
/// backoff. Single private channel per user:
///   - `user.{user_id}`                                (all file + folder events)
///
/// View-side filtering happens in the Riverpod state notifiers via
/// `currentFolderId` — the server no longer scopes broadcasts by folder,
/// so navigating between folders does not require WS re-subscribe.
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
  String? _userId;
  Timer? _reconnectTimer;
  // Pusher server assigns a socket_id on `pusher:connection_established`.
  // We need that exact id when calling /broadcasting/auth — passing a
  // hard-coded placeholder fails the HMAC check on the server side.
  String? _socketId;

  Stream<RealtimeEvent> get events => _events.stream;
  Stream<RealtimeState> get state => _state.stream;
  RealtimeState get currentState => _currentState;

  static const _maxBackoffMs = 30000;
  static const _backoffJitter = 0.2;

  Future<void> connect({
    required RealtimeConfig config,
    required String userId,
  }) async {
    await disconnect();

    _config = config;
    _userId = userId;
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
    final data = msg['data'];

    // Pusher control events.
    if (event == 'pusher:error') {
      _setState(RealtimeState.reconnecting);
      _scheduleReconnect();
      return;
    }
    if (event == 'pusher:connection_established' ||
        event == 'pusher:subscription_succeeded') {
      // Pull socket_id off the connection_established payload so the
      // next /broadcasting/auth call has the correct value to sign.
      if (event == 'pusher:connection_established' && data is String) {
        try {
          final m = jsonDecode(data) as Map<String, dynamic>;
          final sid = m['socket_id'] as String?;
          if (sid != null && sid.isNotEmpty) _socketId = sid;
        } catch (_) {
          // ignore malformed payload
        }
      }
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
  }

  Future<Map<String, dynamic>> _authorizeChannel(String channel) async {
    final url = Uri.parse(_config!.authEndpoint);
    // Pusher requires the socket_id assigned by the broker on
    // `pusher:connection_established`. If we don't have one yet (race
    // between WS open and the established frame), wait briefly — the
    // server sends it within milliseconds of `WebSocketChannel.ready`.
    var socketId = _socketId;
    if (socketId == null) {
      for (var i = 0; i < 25; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
        socketId = _socketId;
        if (socketId != null) break;
      }
    }
    if (socketId == null) {
      throw StateError(
        'No socket_id from pusher:connection_established; cannot auth channel.',
      );
    }
    final res = await http.post(
      url,
      headers: {
        'Authorization': 'Bearer ${_config!.token}',
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: {
        'socket_id': socketId,
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
    final userId = _userId;
    final channel = _channel;
    if (userId == null || channel == null) return;

    // Single per-user channel. Backend wraps the channel name in
    // PrivateChannel('user-{userId}') which Laravel prefixes with
    // 'private-' on the wire, so the actual Reverb/Pusher channel is
    // 'private-user-{userId}'. The subscribe frame must include that
    // prefix — without it, Reverb classifies it as a public channel and
    // events published to the private channel never reach the subscriber.
    // The matching auth closure in routes/channels.php is registered for
    // 'user-{userId}' (Laravel strips the 'private-' prefix before the
    // match), so we still authorize with the bare 'user-X' form.
    await _subscribe('private-user-$userId');
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