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
      }
    });

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
