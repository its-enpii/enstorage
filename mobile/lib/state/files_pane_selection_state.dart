import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Selection state for the right-hand pane of the two-pane Files layout
/// on tablet (expanded breakpoint).
///
/// - [FilesPaneSelection.none]  → pane shows the "Select a file or folder"
///   placeholder.
/// - [FilesPaneSelection.folder] → pane shows a nested `FilesScreen`
///   for the given folder id.
/// - [FilesPaneSelection.file]  → pane shows the file viewer for the
///   given file id.
///
/// The compact (phone) layout does NOT use this — taps still push a
/// full-screen route via go_router as before.
class FilesPaneSelection {
  const FilesPaneSelection._();

  static const Object none = Object();
  static Object folder(String folderId) => _Selection('folder', folderId);
  static Object file(String fileId, {String? folderId}) =>
      _Selection('file', fileId, extra: folderId);

  static bool isFolder(Object? s) =>
      s is _Selection && s.kind == 'folder';
  static bool isFile(Object? s) => s is _Selection && s.kind == 'file';

  static String? idOf(Object? s) =>
      s is _Selection ? s.id : null;
  static String? fileFolderIdOf(Object? s) =>
      s is _Selection ? s.extra : null;
}

class _Selection {
  const _Selection(this.kind, this.id, {this.extra});
  final String kind;
  final String id;
  final String? extra;

  @override
  bool operator ==(Object other) =>
      other is _Selection &&
      other.kind == kind &&
      other.id == id &&
      other.extra == extra;

  @override
  int get hashCode => Object.hash(kind, id, extra);
}

/// Holds the current two-pane selection. autoDispose so the selection
/// resets whenever the user leaves the Files tab.
final filesPaneSelectionProvider =
    StateProvider.autoDispose<Object?>((ref) => FilesPaneSelection.none);