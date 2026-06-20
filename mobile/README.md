# EnStorage — Mobile

Premium cloud vault mobile client. Flutter + Riverpod + go_router. Pairs with the
[`backend/`](../backend) Laravel API and mirrors the [`web/`](../web) Next.js app.

## Quick start

```bash
cd enstorage/mobile

# 1. Install deps
flutter pub get

# 2. Generate localizations (intl_en.arb / intl_id.arb → AppLocalizations)
flutter gen-l10n

# 3. Run. The default API base is the Android emulator → host at :8000.
#    Pass --dart-define=API_BASE=... to point elsewhere.
flutter run \
  --dart-define=API_BASE=http://10.0.2.2:8000/api/v1
```

> iOS simulator: use `http://127.0.0.1:8080/api/v1`. Physical device: use the
> LAN IP of your dev machine, e.g. `http://192.168.1.13:8080/api/v1`.

## Stack

| Concern | Choice |
|---|---|
| State | Riverpod (`StateNotifier`, `Provider`) |
| Routing | go_router |
| HTTP | dio |
| Token storage | `flutter_secure_storage` (Keychain / EncryptedSharedPrefs) |
| Locale prefs | `shared_preferences` |
| i18n | Flutter intl (ARB → generated `AppLocalizations`) |
| Image cache | `cached_network_image` |
| Pickers | `file_picker`, `image_picker` |
| Viewer | `photo_view` (images), `video_player` (videos) |
| Fonts | `google_fonts` (DM Sans + Inter) |

## Project structure

```
lib/
├── app.dart                    # MaterialApp.router root
├── main.dart                   # bootstrap: prefs, locale, ProviderScope
├── theme/                      # Etheric Cloud design tokens
│   ├── colors.dart             # Palette Enpii → semantic M3 roles
│   ├── spacing.dart
│   ├── radii.dart
│   ├── shadows.dart
│   ├── typography.dart         # DM Sans headings, Inter body
│   └── theme.dart              # ThemeData.dark() build
├── l10n/                       # Source ARB files
│   ├── intl_en.arb
│   ├── intl_id.arb
│   └── gen/                    # Generated AppLocalizations (don't edit)
├── data/
│   ├── api_client.dart         # Dio + Accept-Language + Bearer
│   ├── models/                 # User, FileItem, Folder
│   ├── repositories/           # AuthRepository, FilesRepository
│   └── storage/                # TokenStorage, AppPrefs
├── state/                      # Riverpod controllers
│   ├── auth_state.dart
│   ├── locale_state.dart
│   ├── files_state.dart
│   ├── selection_state.dart
│   └── upload_state.dart
├── router/router.dart          # go_router + auth redirect
├── widgets/                    # Shared primitives
│   ├── etheric_card.dart
│   ├── etheric_fab.dart
│   ├── etheric_button.dart
│   ├── etheric_text_field.dart
│   ├── bottom_nav.dart         # Floating pill, 3 items
│   ├── glass_pill.dart         # Floating toolbar
│   ├── file_icon_box.dart
│   └── selection_bar.dart
└── features/
    ├── auth/                   # LoginScreen, RegisterScreen
    ├── files/                  # FilesScreen, FAB action sheet, create folder
    │   └── widgets/            # FileCard, FolderCard, upload progress toast
    ├── settings/               # SettingsScreen (theme + language + logout)
    └── viewer/                 # FileViewerScreen
```

## Design system

Built on the **Enpii palette** (see `theme/colors.dart`):
- Primary `#3D348B` (deep purple)
- Secondary `#E6AF2E` (gold) — used for the FAB and selected check
- Background `#040303` (near-black, more saturated than web)
- Surface `#1A1D27` (cards lift via tonal contrast, no borders)

Typography: DM Sans for headings (geometric, confident), Inter for body / label /
metadata. Spacing tokens (`cardGap`, `innerPadding`, `containerPadding`,
`sectionMargin`) come from `.design/DESIGN.md`. Shape language: 24–28 px "super-
ellipse" cards, 16 px controls, fully-rounded pill for floating toolbars.

Mobile reference screens live in `.design/`:
- `documents_minimalist_cloud_mobile/` — files grid + sort/filter + FAB + bottom nav
- `new_action_bottom_sheet/` — FAB action sheet
- `file_selection_uploading/` — multi-select mode + upload progress toast

## i18n

- Source of truth: `lib/l10n/intl_en.arb` and `intl_id.arb`
- Default: `id` (Indonesian), per user preference
- Persistence: `shared_preferences` key `enstorage_locale` — survives app restart
- Server coupling: locale is sent on every API call as `Accept-Language: <code>`
  via the Dio interceptor in `data/api_client.dart`. The Laravel backend localizes
  error messages accordingly (see `backend/lang/`).

Add a new key:
1. Add to `intl_en.arb` (with `@key` and `placeholders` if needed)
2. Mirror in `intl_id.arb`
3. Run `flutter gen-l10n`

## Auth flow

- Login / register hit `/api/v1/auth/{login,register}` → returns `{ token, user }`
- Token + user id written to `flutter_secure_storage`
- `AuthController` (Riverpod) hydrates user on launch by reading the token then
  calling `/auth/me`. If the token is invalid, it is cleared on logout.
- `go_router` `redirect` sends unauth users to `/login`, auth users away from
  `/login` and `/register`.

## MVP scope (this build)

- Files tab: load folders + files, drill into folder, sort/filter pills
- FAB → action sheet: New Folder, Upload File, Upload Folder, Scan Document
- Multi-select mode (long-press a card): bulk download / move / rename / delete
  actions in the selection bar; selected cards get the gold check overlay
- File viewer: images (zoom), videos (play/pause + scrubber), others → placeholder
- Upload progress: floating glass pill at bottom-24, mirrors
  `.design/file_selection_uploading`
- Settings: language switcher (id / en), sign out

Not in MVP (iterate next):
- Starred tab
- Search
- Bulk download as zip
- Tablet / landscape layout (will reuse the bottom nav + wider grids)

## Running tests

```bash
flutter test
```

## Troubleshooting

- **"Connection refused" to backend on emulator** — make sure `API_BASE` points
  to `10.0.2.2:8000` (Android emulator) or `127.0.0.1:8000` (iOS simulator),
  not `localhost`. `localhost` resolves to the device itself.
- **Camera / file picker doesn't open** — check Info.plist
  (`NSCameraUsageDescription`, `NSPHotoLibraryUsageDescription`) and
  `AndroidManifest.xml` (`CAMERA`, `READ_MEDIA_*`).
- **`AppLocalizations.of(context)` returns null** — make sure
  `flutter gen-l10n` has run after editing ARB files.
- **Linter complains about `withOpacity`** — we use `withValues(alpha: ...)`
  which is the Material 3 way. Don't mix the two.
