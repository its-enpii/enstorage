import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/gen/app_localizations.dart';
import '../../../state/files_pane_selection_state.dart';
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
    return Row(
      children: [
        // The list pane reuses the existing FilesScreen widget, which
        // already provides its own AppBar + FAB + bottom-nav-aware
        // layout. Cap its width so it doesn't dominate the screen.
        SizedBox(
          width: 360,
          child: list,
        ),
        const VerticalDivider(width: 1, thickness: 1),
        const Expanded(child: FilesDetailPane()),
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
      // We need filename + mime. The simplest path is to look up the
      // file via the existing list cache. If the cache doesn't have
      // it, the viewer falls back to generic defaults.
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
/// two-pane shell. FileViewerScreen already builds its own Scaffold +
/// AppBar, so we just return it directly.
class _FileViewerHost extends StatelessWidget {
  const _FileViewerHost({required this.fileId, this.folderId});
  final String fileId;
  final String? folderId;

  @override
  Widget build(BuildContext context) {
    // FileViewerScreen needs filename + mime. The list pane (left) is
    // the source of truth; passing the fileId alone lets the viewer
    // fall back to defaults. The list pane state could be lifted to
    // a provider later if the viewer needs richer metadata.
    return FileViewerScreen(
      fileId: fileId,
      filename: 'File',
      mime: 'application/octet-stream',
      folderId: folderId,
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