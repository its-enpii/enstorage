/// Riverpod providers for the realtime WS service.
///
/// `realtimeProvider` exposes the [RealtimeService] singleton.
/// `realtimeBootstrapProvider` is a one-shot listener (mounted at
/// app root) that:
///   - watches auth state changes and connect/disconnect accordingly
///   - reads `client_keys` from the user payload and starts the
///     service when authenticated
///   - watches folder navigation and updates the active folder id
///
/// Also registers the files-controller-provider accessor so handlers
/// can refresh folder listings on folder events.
library;

import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../state/files_state.dart' as fs;
import '../../state/refresh_signal_state.dart' as signals;
import 'realtime_event.dart';
import 'realtime_handlers.dart';
import 'realtime_service.dart';

final realtimeServiceProvider = Provider<RealtimeService>((ref) {
  final svc = RealtimeService();
  ref.onDispose(() {
    svc.disconnect();
  });
  return svc;
});

final realtimeStateProvider = StreamProvider<RealtimeState>((ref) {
  final svc = ref.watch(realtimeServiceProvider);
  return svc.state;
});

/// Fires events into Riverpod state via [applyEventToRiverpod].
/// Keeps one subscription alive for the provider's lifetime.
final realtimeEventsProvider = StreamProvider<RealtimeEvent>((ref) {
  final svc = ref.watch(realtimeServiceProvider);
  final controller = StreamController<RealtimeEvent>();
  final sub = svc.events.listen((event) {
    try {
      applyEventToRiverpod(event, ref);
      controller.add(event);
    } catch (_) {
      // ignore single-event failures so other events still flow.
    }
  });
  ref.onDispose(() {
    sub.cancel();
    controller.close();
  });
  return controller.stream;
});

/// Connects the realtime service when [RealtimeConnectionContext] is
/// available (set by `main.dart` from auth state). Re-runs on auth
/// change so a new user / token rotation picks up the right scope.
///
/// `clientKeys` must be fetched from `/auth/me` by the caller and
/// passed in via the family parameter — the realtime layer doesn't
/// know how to refresh the user object.
final realtimeConnectionProvider = Provider.family<
    AsyncValue<void>, RealtimeConnectionContext>((ref, ctx) {
  final svc = ref.watch(realtimeServiceProvider);
  if (!ctx.isAuthenticated || ctx.token == null || ctx.token!.isEmpty) {
    svc.disconnect();
    return const AsyncValue.data(null);
  }
  final keys = ctx.clientKeys;
  if (keys == null || keys.isEmpty) {
    // User hasn't uploaded anything yet — no subscriptions possible.
    svc.disconnect();
    return const AsyncValue.data(null);
  }
  // Use the first client_key — see plan Open Q #3 for multi-key
  // reasoning. Multi-key opens N connections; defer.
  final clientKey = keys.first;

  // Defer to microtask so we don't re-enter Provider observers on the
  // same frame the auth state changed.
  Future.microtask(() async {
    try {
      await svc.connect(
        config: RealtimeConfig(
          wsHost: ctx.wsHost,
          wsPort: ctx.wsPort,
          wsScheme: ctx.wsScheme,
          appKey: ctx.appKey,
          authEndpoint: ctx.authEndpoint,
          token: ctx.token!,
        ),
        clientKey: clientKey,
        userId: ctx.userId,
        currentFolderId: ctx.currentFolderId,
      );
    } catch (_) {
      // service has its own reconnect; surface failure via state stream.
    }
  });

  return const AsyncValue.data(null);
});

/// Snapshot of the data needed to open (or tear down) a WS connection.
/// Built by `main.dart` from auth + config state.
class RealtimeConnectionContext {
  const RealtimeConnectionContext({
    required this.isAuthenticated,
    required this.token,
    required this.userId,
    required this.clientKeys,
    required this.wsHost,
    required this.wsPort,
    required this.wsScheme,
    required this.appKey,
    required this.authEndpoint,
    this.currentFolderId,
  });

  final bool isAuthenticated;
  final String? token;
  final String userId;
  final List<String>? clientKeys;
  final String wsHost;
  final int wsPort;
  final String wsScheme;
  final String appKey;
  final String authEndpoint;
  final String? currentFolderId;

  RealtimeConnectionContext copyWith({String? currentFolderId}) =>
      RealtimeConnectionContext(
        isAuthenticated: isAuthenticated,
        token: token,
        userId: userId,
        clientKeys: clientKeys,
        wsHost: wsHost,
        wsPort: wsPort,
        wsScheme: wsScheme,
        appKey: appKey,
        authEndpoint: authEndpoint,
        currentFolderId: currentFolderId ?? this.currentFolderId,
      );
}

/// Set up the accessor + bridge so realtime handlers can refresh the
/// files controller. Call once during app boot (after `runApp`).
void installRealtimeBridges(ProviderContainer container) {
  signals.setAppContainer(container);

  // Bind the folders accessor — returns the files-controller provider
  // for a given parent id so handlers can call .refresh() on it.
  registerFoldersProviderAccessor((String? parentId) {
    return fs.filesControllerProvider(parentId);
  });
}

/// Update the active folder id without restarting the connection.
void updateRealtimeFolder(
  ProviderContainer container,
  String? folderId,
) {
  final svc = container.read(realtimeServiceProvider);
  svc.setCurrentFolder(folderId);
}