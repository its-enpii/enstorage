import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/login_screen.dart';
import '../features/auth/register_screen.dart';
import '../features/files/files_screen.dart';
import '../features/home/home_screen.dart';
import '../features/settings/google_accounts_screen.dart';
import '../features/settings/settings_screen.dart';
import '../features/starred/starred_screen.dart';
import '../features/viewer/file_viewer_screen.dart';
import '../widgets/app_shell.dart';

/// Build the authenticated app shell — Home, Files, Settings.
/// No redirect logic. The router is swapped to [authRouter] on logout
/// (see `routerConfigProvider`).
GoRouter buildHomeRouter() {
  return GoRouter(
    initialLocation: '/home',
    routes: [
      // Top-level viewer route — push() from any tab doesn't switch branches,
      // so the bottom-nav stays on Home/Settings/Starred while the preview
      // sits on top of everything.
      GoRoute(
        path: '/viewer/:fileId',
        builder: (ctx, st) {
          final extra = (st.extra as Map<String, String>?) ?? const {};
          return FileViewerScreen(
            fileId: st.pathParameters['fileId']!,
            filename: extra['filename'] ?? 'File',
            mime: extra['mime'] ?? 'application/octet-stream',
          );
        },
      ),
      StatefulShellRoute.indexedStack(
        builder: (ctx, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/',
                redirect: (_, __) => '/home',
              ),
              GoRoute(
                path: '/home',
                builder: (ctx, st) => const HomeScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/files',
                builder: (ctx, st) => const FilesScreen(),
                routes: [
                  GoRoute(
                    path: ':folderId',
                    builder: (ctx, st) => FilesScreen(
                      folderId: st.pathParameters['folderId'],
                    ),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/settings',
                builder: (ctx, st) => const SettingsScreen(),
                routes: [
                  GoRoute(
                    path: 'google-accounts',
                    builder: (ctx, st) => const GoogleAccountsScreen(),
                  ),
                ],
              ),
              GoRoute(
                path: '/starred',
                builder: (ctx, st) => const StarredScreen(),
              ),
            ],
          ),
        ],
      ),
    ],
  );
}

/// Build the unauthenticated router — just /login and /register.
/// No redirect logic. The router is swapped to [buildHomeRouter] on
/// successful login.
GoRouter buildAuthRouter() {
  return GoRouter(
    initialLocation: '/login',
    routes: [
      GoRoute(
        path: '/login',
        builder: (ctx, st) => const LoginScreen(),
      ),
      GoRoute(
        path: '/register',
        builder: (ctx, st) => const RegisterScreen(),
      ),
    ],
  );
}

/// Holds the active router. Swapped by [swapRouter] on login/logout.
final routerConfigProvider = StateProvider<GoRouter>((ref) {
  // Should be overridden by the ProviderScope in main(); this default
  // exists so the provider is never in an uninitialized state during
  // tests.
  return buildAuthRouter();
});
