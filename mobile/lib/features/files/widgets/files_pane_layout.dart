import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/gen/app_localizations.dart';
import '../../../data/models/file_item.dart';
import '../../../data/repositories/files_repository.dart';
import '../../../state/files_pane_selection_state.dart';
import '../../../state/files_state.dart';
import '../../../theme/breakpoints.dart';
import '../../../theme/spacing.dart';
import '../../viewer/file_viewer_screen.dart';
import '../files_screen.dart';

/// Two-pane layout for the Files tab.
///
/// - compact: returns [list] as-is.
/// - expanded: row of [list] (left) + [FilesDetailPane] (right) with
///   a vertical divider between them. The right pane is driven by
///   [filesPaneSelectionProvider].
class FilesPaneLayout extends ConsumerWidget {
  const FilesPaneLayout({super.key, required this.list});

  /// The list / grid pane (the existing `FilesScreen`).
  final Widget list;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!Breakpoints.isExpanded(context)) return list;
    final scheme = Theme.of(context).colorScheme;
    return Row(
      children: [
        // The list pane reuses the existing FilesScreen widget, which
        // already provides its own AppBar + FAB + bottom-nav-aware
        // layout. Cap its width so it doesn't dominate the screen.
        SizedBox(
          width: 360,
          child: list,
        ),
        // Vertical separator between list and detail panes: a thin
        // coloured line with breathing room on each side so the two
        // regions read as distinct surfaces, not a single split panel.
        Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.cardGap),
          child: VerticalDivider(
            width: 1,
            thickness: 1,
            color: scheme.outlineVariant,
          ),
        ),
        const SizedBox(width: AppSpacing.cardGap),
        // Right + bottom breathing room around the detail pane so the
        // preview content (image / video / pdf / etc.) doesn't sit
        // flush against the screen edge. Top + left are already
        // separated by the AppBar and the gap before this column.
        // `Expanded` lives on the outer widget so the row can size the
        // detail column; the inner `Padding` only contributes insets.
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(
              right: AppSpacing.cardGap,
              bottom: AppSpacing.cardGap,
            ),
            child: FilesDetailPane(),
          ),
        ),
      ],
    );
  }
}

/// Right-hand pane of the two-pane Files layout.
class FilesDetailPane extends ConsumerWidget {
  const FilesDetailPane({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selection = ref.watch(filesPaneSelectionProvider);
    final scheme = Theme.of(context).colorScheme;
    final l10n = AppLocalizations.of(context)!;

    if (selection == FilesPaneSelection.none) {
      return _Placeholder(
        icon: Icons.touch_app_rounded,
        title: l10n.filesSelectPrompt,
      );
    }

    if (FilesPaneSelection.isFolder(selection)) {
      final id = FilesPaneSelection.idOf(selection)!;
      return FilesScreen(folderId: id);
    }

    if (FilesPaneSelection.isFile(selection)) {
      final fileId = FilesPaneSelection.idOf(selection)!;
      final folderId = FilesPaneSelection.fileFolderIdOf(selection);
      // Pass `folderId` so we can read the file from the existing list
      // cache; the viewer needs filename + mime, both of which are
      // already in `FileItem.mimeType`. Without this, the viewer falls
      // back to `application/octet-stream` and treats every file as a
      // generic download (the "Preview" button never renders).
      return _FileViewerHost(fileId: fileId, folderId: folderId);
    }

    return _Placeholder(
      icon: Icons.help_outline,
      title: scheme.brightness == Brightness.dark
          ? 'Unknown selection'
          : 'Unknown selection',
    );
  }
}

/// Wraps [FileViewerScreen] in a way that's safe to render inside the
/// two-pane shell. Resolves `filename` + `mime` from the list cache so
/// the viewer renders the right widget (image / video / audio / pdf /
/// text) instead of the generic "Download" fallback.
class _FileViewerHost extends ConsumerWidget {
  const _FileViewerHost({required this.fileId, this.folderId});
  final String fileId;
  final String? folderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Prefer the file already loaded in the list cache (avoids an
    // extra API round-trip). Fall back to a direct fetch if the cache
    // doesn't have it (e.g. starred → files jump or cold start).
    final cached = _lookupCached(ref);
    if (cached != null) {
      return FileViewerScreen(
        fileId: cached.id,
        filename: cached.name,
        mime: cached.mimeType,
        folderId: folderId,
      );
    }
    return _ResolvedViewer(fileId: fileId, folderId: folderId);
  }

  FileItem? _lookupCached(WidgetRef ref) {
    final data = ref.read(filesControllerProvider(folderId)).valueOrNull;
    if (data == null) return null;
    for (final f in data.files) {
      if (f.id == fileId) return f;
    }
    return null;
  }
}

/// Loads the file on-demand via [FilesRepository.getFile] when it's
/// not in the list cache yet, then hands it off to [FileViewerScreen].
class _ResolvedViewer extends ConsumerStatefulWidget {
  const _ResolvedViewer({required this.fileId, this.folderId});
  final String fileId;
  final String? folderId;

  @override
  ConsumerState<_ResolvedViewer> createState() => _ResolvedViewerState();
}

class _ResolvedViewerState extends ConsumerState<_ResolvedViewer> {
  late final Future<FileItem> _future =
      ref.read(filesRepositoryProvider).getFile(widget.fileId);

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<FileItem>(
      future: _future,
      builder: (ctx, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snap.hasError || !snap.hasData) {
          // Fall back to the generic viewer; the file id at least
          // lets it attempt the inline download.
          return FileViewerScreen(
            fileId: widget.fileId,
            filename: 'File',
            mime: 'application/octet-stream',
            folderId: widget.folderId,
          );
        }
        final f = snap.data!;
        return FileViewerScreen(
          fileId: f.id,
          filename: f.name,
          mime: f.mimeType,
          folderId: widget.folderId,
        );
      },
    );
  }
}

class _Placeholder extends StatelessWidget {
  const _Placeholder({required this.icon, required this.title});
  final IconData icon;
  final String title;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.sectionMargin),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: scheme.onSurfaceVariant),
            const SizedBox(height: 16),
            Text(
              title,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: scheme.onSurfaceVariant,
                fontSize: 16,
              ),
            ),
          ],
        ),
      ),
    );
  }
}