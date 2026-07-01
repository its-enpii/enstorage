import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'l10n/gen/app_localizations.dart';
import 'router/router.dart';
import 'services/notification_service.dart';
import 'state/auth_state.dart';
import 'state/locale_state.dart';
import 'state/theme_state.dart';
import 'theme/theme.dart';
import 'data/realtime/realtime_provider.dart';
import 'data/api_client.dart';
import 'data/storage/token_storage.dart';

class EnStorageApp extends ConsumerStatefulWidget {
  const EnStorageApp({super.key});

  @override
  ConsumerState<EnStorageApp> createState() => _EnStorageAppState();
}

class _EnStorageAppState extends ConsumerState<EnStorageApp> {
  bool? _lastAuthed;

  @override
  Widget build(BuildContext context) {
    // Watch auth state and swap the router whenever the authenticated /
    // unauthenticated boundary is crossed. Doing this from a single
    // listener (rather than a custom setter on AuthController) keeps
    // the controller framework-agnostic and avoids fighting the
    // StateNotifier API.
    ref.listen<AuthState>(authControllerProvider, (prev, next) {
      debugPrint('[App] auth state: user=${next.user?.email} loading=${next.loading} error=${next.error}');
      final wasAuthed = _lastAuthed ?? next.isAuthenticated;
      final isAuthed = next.isAuthenticated;
      _lastAuthed = isAuthed;
      if (wasAuthed != isAuthed) {
        debugPrint('[App] auth flipped $wasAuthed->$isAuthed, swapping router');
        ref.read(routerConfigProvider.notifier).state =
            isAuthed ? buildHomeRouter() : buildAuthRouter();

        // Drive realtime connection. On login, connect with the user's
        // client_keys. On logout, the service disconnect happens via
        // realtimeConnectionProvider seeing an unauthenticated ctx.
        _connectOrDisconnectRealtime(ref, isAuthed);
      }
    });

    // Kick once at boot for cached-auth sessions (auth state hydrated
    // synchronously from secure storage before runApp).
    _connectOrDisconnectRealtime(ref, ref.read(authControllerProvider).isAuthenticated);

    final router = ref.watch(routerConfigProvider);
    final locale = ref.watch(localeControllerProvider);
    final themeMode = ref.watch(themeModeControllerProvider);
    return MaterialApp.router(
      title: 'EnStorage',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: themeMode,
      routerConfig: router,
      scaffoldMessengerKey: rootScaffoldMessengerKey,
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ],
      onGenerateTitle: (ctx) => AppLocalizations.of(ctx)?.appName ?? 'EnStorage',
    );
  }
}

/// Open / tear down the realtime WS connection in response to auth.
void _connectOrDisconnectRealtime(WidgetRef ref, bool isAuthed) async {
  if (!isAuthed) {
    final svc = ref.read(realtimeServiceProvider);
    await svc.disconnect();
    return;
  }
  final user = ref.read(authControllerProvider).user;
  if (user == null) return;
  final token = await ref.read(tokenStorageProvider).readToken();
  if (token == null || token.isEmpty) return;
  // Wait — we don't have client_keys in mobile. The backend /auth/me
  // response supplies them, but mobile auth state may not have refreshed
  // from the server yet (cached User from secure storage lacks
  // client_keys). Trigger a /auth/me refresh in the background.
  final ctx = RealtimeConnectionContext(
    isAuthenticated: true,
    token: token,
    userId: user.id,
    clientKeys: user.clientKeys.isNotEmpty
        ? user.clientKeys
        : const <String>[],
    wsHost: kReverbHost,
    wsPort: kReverbPort,
    wsScheme: kReverbScheme,
    appKey: kReverbAppKey,
    authEndpoint: '${kApiBase.replaceFirst('/api/v1', '')}/broadcasting/auth',
  );
  if (ctx.clientKeys == null || ctx.clientKeys!.isEmpty) {
    // No client_keys → no file events possible yet. Skip until first
    // upload completes (then refresh /auth/me from AuthController).
    return;
  }
  ref.read(realtimeConnectionProvider(ctx));
}
