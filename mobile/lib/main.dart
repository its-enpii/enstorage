import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'app.dart';
import 'services/notification_service.dart';
import 'data/api_client.dart';
import 'data/repositories/auth_repository.dart';
import 'data/storage/prefs.dart';
import 'data/storage/token_storage.dart';
import 'router/router.dart';
import 'state/auth_state.dart';
import 'state/locale_state.dart';
import 'state/refresh_signal_state.dart';
import 'state/theme_state.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Allow all orientations so the app can take advantage of larger
  // screens (tablet / foldable) in landscape as well as portrait.
  // Pages themselves adapt via Breakpoints / ResponsiveContainer.
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
    DeviceOrientation.landscapeLeft,
    DeviceOrientation.landscapeRight,
  ]);
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
  ));

  final prefs = await AppPrefs.create();
  final saved = prefs.locale;
  final initialLocale = saved == null
      ? null
      : supportedLocales.firstWhere(
          (l) => l.languageCode == saved,
          orElse: () => supportedLocales.first,
        );

  // Read the cached auth state synchronously so the *first* router is
  // the right one — no flash of the login screen on cold start when
  // there's a valid session, and no flash of the home screen after
  // logout. The AuthController will fire a silent /me in the background
  // to pick up server-side changes.
  final tokens = TokenStorage();
  // Pre-warm the in-memory token mirror so widgets that build image
  // URLs (which need a sync `readTokenSync()`) get the token immediately
  // on first frame.
  await tokens.readToken();
  final cachedUser = await tokens.readUser();

  final container = ProviderContainer(
    overrides: [
      localeControllerProvider.overrideWith((ref) {
        final api = ref.read(apiClientProvider);
        final ctrl = LocaleController(prefs, api);
        if (initialLocale != null) {
          // setLocale is async; we don't await — UI is reactive.
          // ignore: discarded_futures
          ctrl.setLocale(initialLocale);
        }
        return ctrl;
      }),
      themeModeControllerProvider.overrideWith((ref) {
        return ThemeModeController(prefs);
      }),
      authControllerProvider.overrideWith((ref) {
        return AuthController(
          ref.watch(authRepositoryProvider),
          ref.watch(tokenStorageProvider),
          ref,
          initialUser: cachedUser,
        );
      }),
      // Pre-seed the router with the correct shape for the cached
      // auth state. login() / logout() swap this to the other router
      // (driven by `app.dart`'s listener).
      routerConfigProvider.overrideWith((ref) {
        return cachedUser != null
            ? buildHomeRouter()
            : buildAuthRouter();
      }),
    ],
  );

  // Share container globally so background FCM handler (yang gak punya
  // BuildContext) bisa emit refresh signals. Di-set SEBELUM runApp.
  setAppContainer(container);

  // Firebase + FCM setup — pakai container untuk register token
  // saat FCM ready (baik initial token maupun rotation).
  await initNotifications(
    onTokenReady: (token) async {
      debugPrint('[main] onTokenReady: ${token.substring(0, 20)}...');
      // Skip kalau user belum login — endpoint butuh Bearer token.
      // FCM rotation listener akan memanggil ulang setelah auth ready.
      final hasAuth = await container.read(tokenStorageProvider).readToken() != null;
      if (!hasAuth) {
        debugPrint('[main] onTokenReady: no auth token, deferring register');
        return;
      }
      await registerDeviceTokenByToken(token, container);
    },
  );

  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const EnStorageApp(),
    ),
  );
}
