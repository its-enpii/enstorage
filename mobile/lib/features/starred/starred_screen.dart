import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../data/models/file_item.dart';
import '../../data/models/folder.dart';
import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../files/widgets/file_card.dart';
import '../files/widgets/folder_card.dart';

class StarredScreen extends ConsumerStatefulWidget {
  const StarredScreen({super.key});

  @override
  ConsumerState<StarredScreen> createState() => _StarredScreenState();
}

class _StarredScreenState extends ConsumerState<StarredScreen> {
  Future<_StarredData>? _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_StarredData> _load() async {
    final repo = ref.read(filesRepositoryProvider);
    final results = await Future.wait([
      repo.listStarredFolders(),
      repo.listStarredFiles(),
    ]);
    return _StarredData(
      folders: results[0] as List<Folder>,
      files: results[1] as List<FileItem>,
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            const Icon(Icons.star_rounded, color: AppColors.secondary, size: 22),
            const SizedBox(width: 8),
            Text(l10n.starredTitle),
          ],
        ),
      ),
      body: SafeArea(
        top: false,
        child: FutureBuilder<_StarredData>(
          future: _future,
          builder: (ctx, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snap.hasError) {
              return _Error(
                message: snap.error.toString(),
                onRetry: () => setState(() => _future = _load()),
              );
            }
            final data = snap.data!;
            if (data.folders.isEmpty && data.files.isEmpty) {
              return _Empty();
            }
            return RefreshIndicator(
              onRefresh: () async {
                setState(() => _future = _load());
                await _future;
              },
              child: _Body(
                folders: data.folders,
                files: data.files,
                onFolderTap: (f) => context.go('/files/${f.id}'),
                onFileTap: (f) {
                  if (f.uploadStatus == UploadStatus.done) {
                    context.push(
                      '/viewer/${f.id}',
                      extra: {'filename': f.name, 'mime': f.mimeType},
                    );
                  }
                },
              ),
            );
          },
        ),
      ),
    );
  }
}

class _StarredData {
  const _StarredData({required this.folders, required this.files});
  final List<Folder> folders;
  final List<FileItem> files;
}

class _Body extends StatelessWidget {
  const _Body({
    required this.folders,
    required this.files,
    required this.onFolderTap,
    required this.onFileTap,
  });
  final List<Folder> folders;
  final List<FileItem> files;
  final void Function(Folder) onFolderTap;
  final void Function(FileItem) onFileTap;

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.containerPadding,
        8,
        AppSpacing.containerPadding,
        140,
      ),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisSpacing: AppSpacing.cardGap,
        crossAxisSpacing: AppSpacing.cardGap,
        childAspectRatio: 1.1,
      ),
      itemCount: folders.length + files.length,
      itemBuilder: (ctx, i) {
        if (i < folders.length) {
          final f = folders[i];
          return FolderCard(folder: f, onTap: () => onFolderTap(f), onLongPress: () {});
        }
        final f = files[i - folders.length];
        return FileCard(file: f, onTap: () => onFileTap(f), onLongPress: () {});
      },
    );
  }
}

class _Empty extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                color: AppColors.surfaceHigh,
                borderRadius: BorderRadius.circular(20),
              ),
              child: const Icon(Icons.star_border_rounded,
                  size: 36, color: AppColors.onSurfaceVariant),
            ),
            const SizedBox(height: 20),
            Text(l10n.starredEmpty, style: AppTypography.bodyLg),
            const SizedBox(height: 6),
            Text(
              l10n.starredEmptyDesc,
              textAlign: TextAlign.center,
              style: AppTypography.bodyMd.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Error extends StatelessWidget {
  const _Error({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, size: 48, color: AppColors.error),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: AppTypography.bodyMd),
            const SizedBox(height: 12),
            TextButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('Retry'),
            ),
          ],
        ),
      ),
    );
  }
}
