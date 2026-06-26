import 'dart:async';
import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../data/api_client.dart';
import '../../data/models/file_item.dart';
import '../../data/models/folder.dart';
import '../../data/repositories/files_repository.dart';
import '../../data/storage/token_storage.dart';
import '../../services/notification_service.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/breakpoints.dart';
import '../../state/files_state.dart';
import '../../state/folder_state.dart';
import '../../state/selection_state.dart';
import '../../state/files_pane_selection_state.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_fab.dart';
import '../../widgets/app_snackbar.dart';
import '../../widgets/app_dialog.dart';
import 'create_action_sheet.dart';
import 'create_folder_dialog.dart';
import 'camera_capture.dart';
import 'sort_sheet.dart';
import 'filter_sheet.dart';
import 'share_dialog.dart';
import 'widgets/file_card.dart';
import 'widgets/folder_card.dart';

class FilesScreen extends ConsumerStatefulWidget {
  const FilesScreen({super.key, this.folderId});
  final String? folderId;

  @override
  ConsumerState<FilesScreen> createState() => _FilesScreenState();
}

class _FilesScreenState extends ConsumerState<FilesScreen> {
  final _scroll = ScrollController();
  FileListScope _scope = FileListScope.all;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_maybeLoadMore);
  }

  void _maybeLoadMore() {
    if (!_scroll.hasClients) return;
    final pos = _scroll.position;
    if (pos.pixels >= pos.maxScrollExtent - 240) {
      ref.read(filesControllerProvider(widget.folderId).notifier).loadMore();
    }
  }

  @override
  void dispose() {
    _scroll
      ..removeListener(_maybeLoadMore)
      ..dispose();
    super.dispose();
  }

  void _openFolder(Folder f) {
    if (Breakpoints.isExpanded(context)) {
      // Two-pane mode: render this folder in the right pane.
      ref.read(filesPaneSelectionProvider.notifier).state =
          FilesPaneSelection.folder(f.id);
    } else {
      context.go('/files/${f.id}');
    }
  }

  void _openFile(FileItem f) {
    if (Breakpoints.isExpanded(context)) {
      // Two-pane mode: render the file viewer in the right pane.
      ref.read(filesPaneSelectionProvider.notifier).state =
          FilesPaneSelection.file(
        f.id,
        folderId: widget.folderId ?? f.folderId,
      );
    } else {
      context.push(
        '/viewer/${f.id}',
        extra: {
          'filename': f.name,
          'mime': f.mimeType,
          'folderId': widget.folderId ?? f.folderId,
        },
      );
    }
  }

  Future<void> _onFab() async {
    final action = await showCreateActionSheet(context);
    if (action == null || !mounted) return;
    switch (action) {
      case CreateAction.newFolder:
        final name = await showCreateFolderDialog(context);
        if (name != null && name.isNotEmpty) {
          await ref
              .read(filesControllerProvider(widget.folderId).notifier)
              .createFolder(name);
        }
      case CreateAction.uploadFile:
        await _uploadFile();
      case CreateAction.scanDocument:
        await runCameraCapture(context, folderId: widget.folderId);
    }
  }

  Future<void> _uploadFile() async {
    final result = await FilePicker.platform.pickFiles(allowMultiple: false);
    if (result == null || result.files.isEmpty) return;
    final picked = result.files.first;
    final path = picked.path;
    if (path == null) return;

    final repo = ref.read(filesRepositoryProvider);
    final filename = picked.name;
    // Bikin notif progress lokal (kayak Chrome download).
    showUploadProgress(filename: filename, progress: 0);
    try {
      await repo.uploadFile(
        path: path,
        filename: filename,
        folderId: widget.folderId,
        onProgress: (sent, total) {
          final pct = total == 0 ? 0 : ((sent / total) * 100).round();
          showUploadProgress(filename: filename, progress: pct);
        },
      );
      // HTTP done — masuk fase 2 (backend upload ke GDrive). Notif jadi indeterminate.
      showUploadProgress(filename: filename, progress: 0, indeterminate: true);
      // FCM upload.complete nanti append file baru ke list (no refresh).
    } catch (e) {
      finishUpload(filename: filename, success: false, body: e.toString());
    }
  }

  void _toggleSelect(String id) {
    ref.read(selectionControllerProvider.notifier).toggle(id);
  }

  void _enterSelectMode(String id) {
    ref.read(selectionControllerProvider.notifier).toggle(id);
  }

  void _exitSelectMode() {
    ref.read(selectionControllerProvider.notifier).clear();
  }

  Future<void> _bulkDownload() async {
    final l10n = AppLocalizations.of(context)!;
    final selection = ref.read(selectionControllerProvider);
    final repo = ref.read(filesRepositoryProvider);
    final token = await ref.read(tokenStorageProvider).readToken();
    final api = ref.read(apiClientProvider);
    int success = 0;
    for (final id in selection.ids) {
      try {
        final dir = await getTemporaryDirectory();
        final file = File('${dir.path}/$id');
        final url = repo.downloadUrl(id, token: token, inline: false);
        await api.dio.download(url, file.path);
        success += 1;
      } catch (_) {
        // ignore individual failures
      }
    }
    if (mounted) {
      showAppSnackBar(
        context,
        '${l10n.filesDownloadStarted} ($success/${selection.ids.length})',
        variant: AppSnackBarVariant.info,
      );
    }
    _exitSelectMode();
  }

  Future<void> _bulkShare() async {
    final l10n = AppLocalizations.of(context)!;
    final selection = ref.read(selectionControllerProvider);
    if (selection.ids.isEmpty) return;

    // Resolve names from the in-memory list so we don't have to refetch
    // metadata. Falls back to the id when the file isn't in the current
    // scope (e.g. selected from search results).
    final files = ref.read(filesControllerProvider(widget.folderId)).valueOrNull;
    final nameById = <String, String>{
      for (final f in files?.files ?? const <FileItem>[]) f.id: f.name,
    };

    final repo = ref.read(filesRepositoryProvider);
    final token = await ref.read(tokenStorageProvider).readToken();
    final api = ref.read(apiClientProvider);
    if (!mounted) return;

    // Show a progress snackbar while we pull the bytes.
    showAppSnackBar(
      context,
      l10n.filesSharePreparing,
      variant: AppSnackBarVariant.info,
      duration: const Duration(seconds: 2),
    );

    final dir = await getTemporaryDirectory();
    final List<File> downloaded = [];
    for (final id in selection.ids) {
      try {
        final name = nameById[id] ?? id;
        final file = File('${dir.path}/share_${id}_$name');
        final url = repo.downloadUrl(id, token: token, inline: false);
        await api.dio.download(url, file.path);
        downloaded.add(file);
      } catch (_) {
        // skip individual failures
      }
    }

    if (!mounted) return;
    if (downloaded.isEmpty) {
      showAppSnackBar(context, l10n.filesShareFailed,
          variant: AppSnackBarVariant.error);
      return;
    }
    try {
      await Share.shareXFiles(
        downloaded.map((f) => XFile(f.path)).toList(),
        subject: l10n.appName,
      );
    } catch (_) {
      if (!mounted) return;
      showAppSnackBar(context, l10n.filesShareFailed,
          variant: AppSnackBarVariant.error);
    }
  }

  Future<void> _bulkDelete() async {
    final l10n = AppLocalizations.of(context)!;
    final selection = ref.read(selectionControllerProvider);
    final count = selection.count;
    final confirmed = await showAppDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l10n.filesConfirmBulkDeleteTitle(count)),
        content: Text(l10n.filesConfirmBulkDeleteBody(count)),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(l10n.commonCancel),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: TextButton.styleFrom(
                foregroundColor: Theme.of(ctx).colorScheme.error),
            child: Text(l10n.filesConfirmDeleteConfirm),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final idsToDelete = selection.ids.toList();
    try {
      await ref
          .read(filesRepositoryProvider)
          .bulkDeleteFiles(idsToDelete);
      if (mounted) {
        // Update state lokal — list langsung berkurang, no refresh.
        ref
            .read(filesControllerProvider(widget.folderId).notifier)
            .removeFiles(idsToDelete);
      }
    } catch (_) {
      if (mounted) {
        showAppSnackBar(context, l10n.filesDeleteFailed,
            variant: AppSnackBarVariant.error);
      }
    }
    _exitSelectMode();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final state = ref.watch(filesControllerProvider(widget.folderId));
    final controller =
        ref.read(filesControllerProvider(widget.folderId).notifier);
    final selection = ref.watch(selectionControllerProvider);
    final inSelection = selection.isNotEmpty;
    final scheme = Theme.of(context).colorScheme;

    final title = _buildAppBarTitle(context, l10n);

    return Scaffold(
      extendBody: true,
      appBar: inSelection
          ? AppBar(
              automaticallyImplyLeading: false,
              title: Text(
                l10n.filesSelected(selection.count),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.headlineLgMobile.copyWith(
                  color: scheme.onSurface,
                ),
              ),
              actions: [
                IconButton(
                  onPressed: _exitSelectMode,
                  icon: Icon(Icons.close, color: scheme.onSurface),
                  tooltip: l10n.filesSelectMode,
                ),
                IconButton(
                  onPressed: _bulkShare,
                  icon: Icon(
                    Icons.share_outlined,
                    color: scheme.onSurface,
                  ),
                  tooltip: l10n.filesActionsShare,
                ),
                IconButton(
                  onPressed: _bulkDownload,
                  icon: Icon(
                    Icons.download_outlined,
                    color: scheme.onSurface,
                  ),
                  tooltip: l10n.filesActionsDownload,
                ),
                IconButton(
                  onPressed: _bulkDelete,
                  icon: Icon(
                    Icons.delete_outline,
                    color: scheme.error,
                  ),
                  tooltip: l10n.filesActionsDelete,
                ),
              ],
            )
          : AppBar(
              title: title,
              actions: [
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: CircleAvatar(
                    radius: 18,
                    backgroundColor: scheme.surfaceContainerHigh,
                    child: Icon(Icons.person_outline,
                        size: 20, color: scheme.onSurface),
                  ),
                ),
              ],
            ),
      body: Stack(
        children: [
          // Full-page spinner only on the very first load, when there's
          // no prior data to keep visible. Subsequent refreshes (search
          // keystrokes, sort, filter) let _Body stay mounted and just
          // show a thin top progress bar via `isRefreshing`.
          if (state.isLoading && state.valueOrNull == null)
            const Center(child: CircularProgressIndicator())
          else if (state.hasError && state.valueOrNull == null)
            _Error(
              message: state.error.toString(),
              onRetry: () => controller.refresh(),
            )
          else
            _Body(
              data: state.valueOrNull ?? const FilesData(),
              folderId: widget.folderId,
              scrollController: _scroll,
              scope: _scope,
              onScopeChanged: (s) => setState(() => _scope = s),
              inSelection: inSelection,
              isRefreshing: controller.isRefreshing,
              onClearSearch: controller.clearSearch,
              onFolderTap: (f) {
                if (inSelection) {
                  _toggleSelect(f.id);
                } else {
                  _openFolder(f);
                }
              },
              onFolderShare: _shareFolder,
              onFileTap: (f) {
                if (inSelection) {
                  _toggleSelect(f.id);
                } else {
                  _openFile(f);
                }
              },
              onLongPress: _enterSelectMode,
              onSortTap: () => _openSortSheet(context, controller),
              onFilterTap: () => _openFilterSheet(context, controller),
              onSearchChange: controller.setSearch,
              activeFilter: controller.filter,
            ),
          if (!inSelection)
            Positioned(
              right: AppSpacing.fabHorizontal,
              bottom: AppSpacing.fabBottom,
              child: EthericFab(onTap: _onFab),
            ),
        ],
      ),
    );
  }

  Future<void> _openSortSheet(
      BuildContext context, FilesController controller) async {
    final sort = await showSortSheet(context, controller.filter);
    if (sort != null) {
      controller.setSort(sort.sort, ascending: sort.ascending);
    }
  }

  Future<void> _openFilterSheet(
      BuildContext context, FilesController controller) async {
    final next = await showFilterSheet(context, controller.filter, _scope);
    if (next != null) {
      setState(() {
        if (next.scope != null) _scope = next.scope!;
      });
      final ctrl = controller;
      if (next.type != null && next.type != ctrl.filter.type) {
        ctrl.setType(next.type!);
      }
      if (next.starredOnly != ctrl.filter.starredOnly) {
        ctrl.toggleStarred();
      }
    }
  }

  Future<void> _shareFolder(Folder folder) async {
    final selection = ref.read(selectionControllerProvider);
    if (selection.isNotEmpty) return;
    final repo = ref.read(filesRepositoryProvider);
    final fresh = await repo.getFolder(folder.id);
    if (!mounted) return;
    final updated = await showAppDialog<Folder>(
      context: context,
      builder: (ctx) => ShareDialog(target: ShareFolderTarget(fresh)),
    );
    if (updated != null) {
      // Sync the new share_token back into the list state.
      ref
          .read(filesControllerProvider(widget.folderId).notifier)
          .replaceFolder(updated);
    }
  }

  /// AppBar title:
  /// - root: localized "File Manager"
  /// - inside a folder: the folder's name (fetched via [folderProvider]),
  ///   falling back to a generic "…" placeholder while loading, or to
  ///   "Folder" if the fetch failed.
  Widget _buildAppBarTitle(BuildContext context, AppLocalizations l10n) {
    final folderId = widget.folderId;
    if (folderId == null) {
      return Text(l10n.filesRootTitle);
    }
    final asyncFolder = ref.watch(folderProvider(folderId));
    return asyncFolder.when(
      data: (f) => Text(
        f.name,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      loading: () => const Text('…'),
      error: (_, __) => const Text('Folder'),
    );
  }
}

class _Body extends StatefulWidget {
  const _Body({
    required this.data,
    required this.folderId,
    required this.scrollController,
    required this.scope,
    required this.onScopeChanged,
    required this.inSelection,
    required this.isRefreshing,
    required this.onClearSearch,
    required this.onFolderTap,
    required this.onFileTap,
    required this.onLongPress,
    this.onFolderShare,
    required this.onSortTap,
    required this.onFilterTap,
    required this.onSearchChange,
    required this.activeFilter,
  });

  final FilesData data;
  final String? folderId;
  final ScrollController scrollController;
  final FileListScope scope;
  final ValueChanged<FileListScope> onScopeChanged;
  final bool inSelection;
  final bool isRefreshing;
  final VoidCallback onClearSearch;
  final void Function(Folder) onFolderTap;
  final void Function(FileItem) onFileTap;
  final void Function(String id) onLongPress;
  final void Function(Folder)? onFolderShare;
  final VoidCallback onSortTap;
  final VoidCallback onFilterTap;
  final ValueChanged<String> onSearchChange;
  final FilesFilter activeFilter;

  @override
  State<_Body> createState() => _BodyState();
}

class _BodyState extends State<_Body> {
  late final TextEditingController _searchCtrl;

  @override
  void initState() {
    super.initState();
    _searchCtrl = TextEditingController(text: widget.activeFilter.search);
  }

  @override
  void didUpdateWidget(covariant _Body old) {
    super.didUpdateWidget(old);
    // Keep the field in sync if the filter is reset externally.
    if (widget.activeFilter.search != _searchCtrl.text) {
      _searchCtrl.text = widget.activeFilter.search;
    }
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final data = widget.data;
    final scope = widget.scope;
    final visibleFolders = (scope == FileListScope.files)
        ? const <Folder>[]
        : data.folders;
    final visibleFiles = (scope == FileListScope.folders)
        ? const <FileItem>[]
        : data.files;

    final searchQuery = widget.activeFilter.search.trim();
    if (data.isEmpty) {
      return _Empty(
        l10n: l10n,
        searchQuery: searchQuery.isEmpty ? null : searchQuery,
        onClearSearch: widget.onClearSearch,
      );
    }

    return CustomScrollView(
      controller: widget.scrollController,
      slivers: [
        // Thin progress bar — only while a refetch is in flight, so
        // the list below stays visible. The bar sits above the search
        // field and animates out when the refetch completes.
        if (widget.isRefreshing)
          const SliverToBoxAdapter(
            child: LinearProgressIndicator(minHeight: 2),
          ),
        // Full-width search input.
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.containerPadding,
              12,
              AppSpacing.containerPadding,
              8,
            ),
            child: _SearchField(
              controller: _searchCtrl,
              onChanged: widget.onSearchChange,
            ),
          ),
        ),
        // Urutkan + Filter row.
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.containerPadding,
              0,
              AppSpacing.containerPadding,
              16,
            ),
            child: Row(
              children: [
                Expanded(
                  child: _PillButton(
                    icon: Icons.sort,
                    label: l10n.sortLabel,
                    onTap: widget.onSortTap,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _PillButton(
                    icon: Icons.filter_list,
                    label: l10n.filterLabel,
                    active: widget.activeFilter.starredOnly ||
                        widget.activeFilter.type != FileTypeFilter.all ||
                        widget.scope != FileListScope.all,
                    onTap: widget.onFilterTap,
                  ),
                ),
              ],
            ),
          ),
        ),
        SliverPadding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.containerPadding,
            0,
            AppSpacing.containerPadding,
            140,
          ),
          sliver: SliverGrid(
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: Breakpoints.gridCount(context),
              mainAxisSpacing: AppSpacing.cardGap,
              crossAxisSpacing: AppSpacing.cardGap,
              childAspectRatio: 1.1,
            ),
            delegate: SliverChildBuilderDelegate(
              (ctx, i) {
                if (i < visibleFolders.length) {
                  final f = visibleFolders[i];
                  return FolderCard(
                    folder: f,
                    onTap: () => widget.onFolderTap(f),
                    onLongPress: () => widget.onLongPress(f.id),
                    onShare: widget.onFolderShare == null
                        ? null
                        : () => widget.onFolderShare!(f),
                  );
                }
                final f = visibleFiles[i - visibleFolders.length];
                return FileCard(
                  file: f,
                  parentFolderId: widget.folderId,
                  onTap: () => widget.onFileTap(f),
                  onLongPress: () => widget.onLongPress(f.id),
                );
              },
              childCount: visibleFolders.length + visibleFiles.length,
            ),
          ),
        ),
      ],
    );
  }
}

class _SearchField extends StatelessWidget {
  const _SearchField({required this.controller, required this.onChanged});
  final TextEditingController controller;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;
    return TextField(
      controller: controller,
      textInputAction: TextInputAction.search,
      onChanged: onChanged,
      decoration: InputDecoration(
        hintText: l10n.searchPlaceholder,
        prefixIcon: const Icon(Icons.search),
        suffixIcon: ValueListenableBuilder<TextEditingValue>(
          valueListenable: controller,
          builder: (_, value, __) {
            if (value.text.isEmpty) return const SizedBox.shrink();
            return IconButton(
              icon: const Icon(Icons.close),
              onPressed: () {
                controller.clear();
                onChanged('');
              },
            );
          },
        ),
        filled: true,
        fillColor: scheme.surfaceContainer,
        contentPadding: const EdgeInsets.symmetric(vertical: 0),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: BorderSide(color: scheme.primary, width: 1.5),
        ),
      ),
    );
  }
}

class _PillButton extends StatelessWidget {
  const _PillButton({
    required this.icon,
    required this.label,
    required this.onTap,
    this.active = false,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool active;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainer,
      borderRadius: AppRadii.pillBorder,
      child: InkWell(
        borderRadius: AppRadii.pillBorder,
        onTap: onTap,
        child: Container(
          height: 44,
          decoration: BoxDecoration(
            borderRadius: AppRadii.pillBorder,
            border: active
                ? Border.all(color: scheme.secondary, width: 1.5)
                : null,
            boxShadow: [
              BoxShadow(
                color: scheme.onSurface.withValues(alpha: 0.05),
                offset: const Offset(0, 1),
                blurRadius: 0,
              ),
            ],
          ),
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon,
                  color: active ? scheme.secondary : scheme.onSurfaceVariant,
                  size: 18),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySm.copyWith(
                    color: active ? scheme.secondary : scheme.onSurface,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty({
    required this.l10n,
    this.searchQuery,
    this.onClearSearch,
  });
  final AppLocalizations l10n;
  final String? searchQuery;
  final VoidCallback? onClearSearch;
  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isSearch = searchQuery != null && searchQuery!.isNotEmpty;
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
                color: scheme.surfaceContainerHigh,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Icon(
                isSearch ? Icons.search_off : Icons.folder_open,
                size: 36,
                color: scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 20),
            Text(
              isSearch ? l10n.searchNoResults : l10n.filesEmpty,
              style: AppTypography.bodyLg,
            ),
            const SizedBox(height: 6),
            Text(
              isSearch
                  ? l10n.searchNoResultsDesc(searchQuery!)
                  : l10n.filesEmptyDesc,
              textAlign: TextAlign.center,
              style: AppTypography.bodyMd.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            if (isSearch) ...[
              const SizedBox(height: 16),
              FilledButton.tonalIcon(
                onPressed: onClearSearch,
                icon: const Icon(Icons.close, size: 18),
                label: Text(l10n.searchClear),
              ),
            ],
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
    final scheme = Theme.of(context).colorScheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 48, color: scheme.error),
            const SizedBox(height: 12),
            Text(message,
                textAlign: TextAlign.center,
                style: AppTypography.bodyMd.copyWith(color: scheme.error)),
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
