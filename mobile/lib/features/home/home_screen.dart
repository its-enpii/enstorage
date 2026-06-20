import 'package:cached_network_image/cached_network_image.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../data/api_client.dart';
import '../../data/models/recent_entry.dart';
import '../../data/models/storage_summary.dart';
import '../../data/repositories/files_repository.dart';
import '../../data/repositories/recent_repository.dart';
import '../../data/storage/token_storage.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../state/auth_state.dart';
import '../../state/files_state.dart';
import '../../state/upload_state.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';
import '../../widgets/etheric_fab.dart';
import '../files/camera_capture.dart';
import '../files/create_action_sheet.dart';
import '../files/create_folder_dialog.dart';

class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  Future<void> _onFab() async {
    final action = await showCreateActionSheet(context);
    if (action == null || !mounted) return;
    switch (action) {
      case CreateAction.newFolder:
        final name = await showCreateFolderDialog(context);
        if (name != null && name.isNotEmpty) {
          // Use the root-files controller to create at the top level.
          await ref.read(filesControllerProvider(null).notifier).createFolder(name);
        }
      case CreateAction.uploadFile:
        await _uploadFile();
      case CreateAction.scanDocument:
        await runCameraCapture(context);
    }
  }

  Future<void> _uploadFile() async {
    final file = await FilePicker.platform.pickFiles();
    if (file == null || file.files.isEmpty) return;
    final picked = file.files.first;
    if (picked.path == null) return;
    final repo = ref.read(filesRepositoryProvider);
    final ctrl = ref.read(uploadControllerProvider.notifier);
    final id = ctrl.start(picked.name);
    try {
      await repo.uploadFile(
        path: picked.path!,
        filename: picked.name,
        onProgress: (s, t) => ctrl.update(id, sent: s, total: t),
      );
      ctrl.complete(id);
    } catch (_) {
      ctrl.fail(id);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final user = ref.watch(authControllerProvider).user;
    final name = user?.name ?? '';
    final topPad = MediaQuery.of(context).viewPadding.top;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Stack(
          children: [
            CustomScrollView(
              slivers: [
                SliverPadding(
                  padding: EdgeInsets.fromLTRB(
                    AppSpacing.containerPadding,
                    12 + topPad * 0.3,
                    AppSpacing.containerPadding,
                    0,
                  ),
                  sliver: SliverToBoxAdapter(
                    child: _Greeting(name: name, l10n: l10n),
                  ),
                ),
                const SliverPadding(
                  padding: EdgeInsets.only(top: 16),
                  sliver: SliverToBoxAdapter(child: _StorageCard()),
                ),
                const SliverPadding(
                  padding: EdgeInsets.only(top: 24),
                  sliver: SliverToBoxAdapter(child: _QuickActions()),
                ),
                const SliverPadding(
                  padding: EdgeInsets.only(top: 24),
                  sliver: SliverToBoxAdapter(child: _RecentHeader()),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.containerPadding,
                    12,
                    AppSpacing.containerPadding,
                    140,
                  ),
                  sliver: const _RecentList(),
                ),
              ],
            ),
            Positioned(
              right: AppSpacing.fabHorizontal,
              bottom: AppSpacing.fabBottom,
              child: EthericFab(onTap: _onFab),
            ),
          ],
        ),
      ),
    );
  }
}

class _Greeting extends StatelessWidget {
  const _Greeting({required this.name, required this.l10n});
  final String name;
  final AppLocalizations l10n;
  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: AppColors.primaryContainer,
            borderRadius: BorderRadius.circular(22),
          ),
          child: Center(
            child: Text(
              name.isEmpty ? '?' : name[0].toUpperCase(),
              style: AppTypography.headlineLgMobile.copyWith(
                color: AppColors.onPrimaryContainer,
                fontSize: 20,
              ),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                l10n.homeWelcome,
                style: AppTypography.metadata.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                name,
                style: AppTypography.headlineLgMobile,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _StorageCard extends ConsumerStatefulWidget {
  const _StorageCard();
  @override
  ConsumerState<_StorageCard> createState() => _StorageCardState();
}

class _StorageCardState extends ConsumerState<_StorageCard> {
  Future<StorageSummary?>? _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<StorageSummary?> _load() async {
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.dio.get<Map<String, dynamic>>('/storage/summary');
      final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
      return StorageSummary.fromJson(inner);
    } catch (_) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
      child: FutureBuilder<StorageSummary?>(
        future: _future,
        builder: (ctx, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const _SkeletonBox(height: 120);
          }
          final s = snap.data;
          if (s == null || s.total == 0) {
            return _EmptyStorage(l10n: l10n);
          }
          final pct = (s.used / s.total).clamp(0.0, 1.0);
          final usedStr = _humanSize(s.used);
          final totalStr = _humanSize(s.total);
          final freeStr = _humanSize(s.free);
          return EthericCard(
            padding: const EdgeInsets.all(AppSpacing.innerPadding),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(
                      l10n.homeStorageTitle,
                      style: AppTypography.labelSm.copyWith(
                        color: AppColors.onSurfaceVariant,
                        letterSpacing: 0.05 * 12,
                      ),
                    ),
                    const Spacer(),
                    Text(
                      l10n.homeAccountsConnected(s.accountsCount),
                      style: AppTypography.metadata.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  l10n.homeStorageUsed(usedStr, totalStr),
                  style: AppTypography.bodyLg.copyWith(
                    color: AppColors.onSurface,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 12),
                ClipRRect(
                  borderRadius: AppRadii.pillBorder,
                  child: LinearProgressIndicator(
                    value: pct,
                    minHeight: 6,
                    backgroundColor: AppColors.surfaceHigh,
                    valueColor: AlwaysStoppedAnimation(
                      pct > 0.9 ? AppColors.error : AppColors.secondary,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  l10n.homeStorageFree(freeStr),
                  style: AppTypography.metadata.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _EmptyStorage extends StatelessWidget {
  const _EmptyStorage({required this.l10n});
  final AppLocalizations l10n;
  @override
  Widget build(BuildContext context) {
    return EthericCard(
      padding: const EdgeInsets.all(AppSpacing.innerPadding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.homeStorageTitle,
            style: AppTypography.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: 8),
          Text(
            l10n.homeNoAccounts,
            style: AppTypography.bodyLg,
          ),
        ],
      ),
    );
  }
}

class _QuickActions extends ConsumerWidget {
  const _QuickActions();

  Future<void> _onUpload(BuildContext context) async {
    final file = await FilePicker.platform.pickFiles();
    if (file == null || file.files.isEmpty) return;
    final picked = file.files.first;
    if (picked.path == null) return;
    final container = ProviderScope.containerOf(context, listen: false);
    final repo = container.read(filesRepositoryProvider);
    final ctrl = container.read(uploadControllerProvider.notifier);
    final id = ctrl.start(picked.name);
    try {
      await repo.uploadFile(
        path: picked.path!,
        filename: picked.name,
        onProgress: (s, t) => ctrl.update(id, sent: s, total: t),
      );
      ctrl.complete(id);
    } catch (_) {
      ctrl.fail(id);
    }
  }

  Future<void> _onCreateFolder(BuildContext context, WidgetRef ref) async {
    final name = await showCreateFolderDialog(context);
    if (name == null || name.isEmpty) return;
    try {
      final folder = await ref
          .read(filesControllerProvider(null).notifier)
          .createFolder(name);
      if (!context.mounted) return;
      context.push('/files/${folder.id}');
    } catch (_) {
      // Silent — user can retry from the Files screen FAB.
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context)!;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.homeQuickActions,
            style: AppTypography.labelSm.copyWith(
              color: AppColors.onSurfaceVariant,
              letterSpacing: 0.05 * 12,
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _ActionTile(
                  icon: Icons.upload_file_outlined,
                  label: l10n.homeActionUpload,
                  onTap: () => _onUpload(context),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _ActionTile(
                  icon: Icons.create_new_folder_outlined,
                  label: l10n.homeActionFolder,
                  onTap: () => _onCreateFolder(context, ref),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _ActionTile(
                  icon: Icons.photo_camera_outlined,
                  label: l10n.homeActionCamera,
                  onTap: () => runCameraCapture(context),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      borderRadius: AppRadii.cardBorder,
      child: InkWell(
        borderRadius: AppRadii.cardBorder,
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 16),
          decoration: BoxDecoration(
            borderRadius: AppRadii.cardBorder,
            boxShadow: const [
              BoxShadow(
                color: Color(0x0DFFFFFF),
                offset: Offset(0, 1),
                blurRadius: 0,
              ),
            ],
          ),
          child: Column(
            children: [
              Icon(icon, color: AppColors.primary, size: 24),
              const SizedBox(height: 8),
              Text(
                label,
                style: AppTypography.labelSm,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RecentHeader extends StatelessWidget {
  const _RecentHeader();
  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
      child: Row(
        children: [
          Text(
            l10n.homeRecentFiles,
            style: AppTypography.labelSm.copyWith(
              color: AppColors.onSurfaceVariant,
              letterSpacing: 0.05 * 12,
            ),
          ),
          const Spacer(),
          TextButton(
            onPressed: () => context.go('/files'),
            child: Text(l10n.homeSeeAll),
          ),
        ],
      ),
    );
  }
}

class _RecentList extends ConsumerStatefulWidget {
  const _RecentList();
  @override
  ConsumerState<_RecentList> createState() => _RecentListState();
}

class _RecentListState extends ConsumerState<_RecentList> {
  static const int _pageSize = 30;

  final List<RecentEntry> _items = [];
  String? _nextCursor;
  bool _loading = false;
  bool _hasMore = true;
  bool _initialLoad = true;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final repo = ref.read(recentRepositoryProvider);
      final page = await repo.fetchRecent(limit: _pageSize, cursor: _nextCursor);
      if (!mounted) return;
      setState(() {
        _items.addAll(page.items);
        _nextCursor = page.nextCursor;
        _hasMore = page.nextCursor != null;
        _initialLoad = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _initialLoad = false;
      });
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_initialLoad) {
      return const SliverToBoxAdapter(
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
          child: _SkeletonBox(height: 80),
        ),
      );
    }
    if (_items.isEmpty) {
      final l10n = AppLocalizations.of(context)!;
      return SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
          child: Text(
            _error != null ? l10n.commonError : l10n.homeNoRecent,
            style: AppTypography.bodyMd.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ),
      );
    }
    return SliverList.separated(
      itemCount: _items.length + (_hasMore ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (ctx, i) {
        if (i >= _items.length) {
          // Trailing loader row — also triggers the next page on first
          // build (so infinite scroll auto-fires as the user scrolls
          // the outer CustomScrollView into view).
          if (!_loading) {
            WidgetsBinding.instance.addPostFrameCallback((_) => _loadMore());
          }
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Center(
              child: SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          );
        }
        return _RecentRow(entry: _items[i]);
      },
    );
  }
}

class _RecentRow extends ConsumerWidget {
  const _RecentRow({required this.entry});
  final RecentEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (entry.isFolder) {
      return _FolderRow(entry: entry);
    }
    return _FileRow(entry: entry);
  }
}

class _FileRow extends ConsumerWidget {
  const _FileRow({required this.entry});
  final RecentEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.watch(filesRepositoryProvider);
    final mime = entry.mimeType ?? 'application/octet-stream';
    final isImage = mime.startsWith('image/');
    final showThumb = isImage && (entry.hasThumbnail ?? false);
    final token = ref.watch(tokenStorageProvider).readTokenSync();
    return Material(
      color: AppColors.surface,
      borderRadius: AppRadii.cardBorder,
      child: InkWell(
        borderRadius: AppRadii.cardBorder,
        onTap: () => context.push(
          '/viewer/${entry.id}',
          extra: {'filename': entry.name, 'mime': mime},
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              _ThumbBox(
                size: 40,
                borderRadius: 10,
                child: showThumb
                    ? CachedNetworkImage(
                        imageUrl: repo.thumbnailUrl(entry.id, token: token),
                        fit: BoxFit.cover,
                        placeholder: (_, __) => _FileIcon(mime: mime),
                        errorWidget: (_, __, ___) => _FileIcon(mime: mime),
                      )
                    : _FileIcon(mime: mime),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      entry.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodyMd.copyWith(
                        color: AppColors.onSurface,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    Text(
                      _humanSize(entry.size ?? 0),
                      style: AppTypography.metadata.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right,
                color: AppColors.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _FolderRow extends StatelessWidget {
  const _FolderRow({required this.entry});
  final RecentEntry entry;

  @override
  Widget build(BuildContext context) {
    final countLabel = _folderSubtitle(entry);
    return Material(
      color: AppColors.surface,
      borderRadius: AppRadii.cardBorder,
      child: InkWell(
        borderRadius: AppRadii.cardBorder,
        onTap: () => context.push('/files/${entry.id}'),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              _ThumbBox(
                size: 40,
                borderRadius: 10,
                child: const Icon(
                  Icons.folder_outlined,
                  color: AppColors.primary,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      entry.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodyMd.copyWith(
                        color: AppColors.onSurface,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    Text(
                      countLabel,
                      style: AppTypography.metadata.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right,
                color: AppColors.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ThumbBox extends StatelessWidget {
  const _ThumbBox({
    required this.child,
    required this.size,
    required this.borderRadius,
  });
  final Widget child;
  final double size;
  final double borderRadius;
  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      clipBehavior: Clip.hardEdge,
      decoration: BoxDecoration(
        color: AppColors.surfaceHigh,
        borderRadius: BorderRadius.circular(borderRadius),
      ),
      child: child,
    );
  }
}

class _FileIcon extends StatelessWidget {
  const _FileIcon({required this.mime});
  final String mime;
  @override
  Widget build(BuildContext context) {
    final icon = mime.startsWith('image/')
        ? Icons.image_outlined
        : Icons.description_outlined;
    return Center(
      child: Icon(icon, color: AppColors.primary, size: 20),
    );
  }
}

String _folderSubtitle(RecentEntry e) {
  final files = e.filesCount ?? 0;
  final folders = e.foldersCount ?? 0;
  final parts = <String>[];
  if (files > 0) parts.add('$files file');
  if (folders > 0) parts.add('$folders folder');
  if (parts.isEmpty) return 'Folder';
  return parts.join(' • ');
}

class _SkeletonBox extends StatelessWidget {
  const _SkeletonBox({required this.height});
  final double height;
  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadii.cardBorder,
      ),
      child: const Center(
        child: SizedBox(
          width: 20,
          height: 20,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      ),
    );
  }
}

String _humanSize(int bytes) {
  if (bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var v = bytes.toDouble();
  var i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return '${v.toStringAsFixed(v >= 10 || i == 0 ? 0 : 1)} ${units[i]}';
}
