import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart' show Options, ResponseType;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:photo_view/photo_view.dart';
import 'package:video_player/video_player.dart';
import 'package:path_provider/path_provider.dart';
import 'package:open_filex/open_filex.dart';

import '../../data/api_client.dart';
import '../../data/models/file_item.dart';
import '../../data/repositories/files_repository.dart';
import '../../data/storage/token_storage.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/colors.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_button.dart';
import '../files/share_dialog.dart';
import '../files/rename_dialog.dart';

class FileViewerScreen extends ConsumerStatefulWidget {
  const FileViewerScreen({
    super.key,
    required this.fileId,
    required this.filename,
    required this.mime,
  });
  final String fileId;
  final String filename;
  final String mime;

  @override
  ConsumerState<FileViewerScreen> createState() => _FileViewerScreenState();
}

class _FileViewerScreenState extends ConsumerState<FileViewerScreen> {
  VideoPlayerController? _video;
  String? _textContent;
  bool _textLoading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.mime.startsWith('video/')) {
      _initVideo();
    } else if (_isText(widget.mime)) {
      _loadText();
    }
  }

  String _category(String mime) {
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('audio/')) return 'audio';
    if (mime == 'application/pdf') return 'pdf';
    if (_isText(mime)) return 'text';
    return 'other';
  }

  bool _isText(String mime) {
    return mime.startsWith('text/') ||
        ['application/json', 'application/xml', 'application/javascript']
            .contains(mime);
  }

  Future<void> _initVideo() async {
    final repo = ref.read(filesRepositoryProvider);
    final token = await ref.read(tokenStorageProvider).readToken();
    final url = repo.downloadUrl(widget.fileId, token: token, inline: true);
    final ctrl = VideoPlayerController.networkUrl(
      Uri.parse(url),
      httpHeaders: const {},
    );
    try {
      await ctrl.initialize();
      if (mounted) setState(() => _video = ctrl);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    }
  }

  Future<void> _loadText() async {
    final repo = ref.read(filesRepositoryProvider);
    final token = await ref.read(tokenStorageProvider).readToken();
    final url = repo.downloadUrl(widget.fileId, token: token, inline: true);
    try {
      // dio's interceptor injects the Bearer token, so a full URL works
      // without manually rebuilding headers/options.
      final res = await ref.read(apiClientProvider).dio.get<dynamic>(
            url,
            options: Options(responseType: ResponseType.plain),
          );
      if (mounted) setState(() {
        _textContent = res.data?.toString() ?? '';
        _textLoading = false;
      });
    } catch (e) {
      if (mounted) setState(() {
        _textContent = 'Gagal memuat konten.';
        _textLoading = false;
      });
    }
  }

  @override
  void dispose() {
    _video?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        iconTheme: const IconThemeData(color: AppColors.onSurface),
        title: Text(
          widget.filename,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.bodyMd.copyWith(
            color: AppColors.onSurface,
            fontWeight: FontWeight.w600,
          ),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.more_vert),
            onPressed: () => _showMenu(context),
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    final repo = ref.read(filesRepositoryProvider);
    switch (_category(widget.mime)) {
      case 'image':
        return _ImageViewer(fileId: widget.fileId, repo: repo);
      case 'video':
        return _VideoViewer(ctrl: _video, error: _error);
      case 'audio':
        return _AudioViewer(fileId: widget.fileId, filename: widget.filename, repo: repo);
      case 'pdf':
        return _PdfViewer(fileId: widget.fileId, repo: repo);
      case 'text':
        if (_textLoading) {
          return const Center(child: CircularProgressIndicator());
        }
        return _TextViewer(content: _textContent ?? '');
      default:
        return _OtherViewer(fileId: widget.fileId, filename: widget.filename, mime: widget.mime, repo: repo);
    }
  }

  void _showMenu(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        final l10n = AppLocalizations.of(ctx)!;
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                leading: const Icon(Icons.download_outlined),
                title: Text(l10n.viewerMenuDownload),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _download();
                },
              ),
              ListTile(
                leading: const Icon(Icons.edit_outlined),
                title: Text(l10n.viewerMenuRename),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _rename();
                },
              ),
              ListTile(
                leading: const Icon(Icons.link),
                title: Text(l10n.viewerMenuCopy),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _copyLink();
                },
              ),
              ListTile(
                leading: const Icon(Icons.share_outlined),
                title: Text(l10n.viewerMenuShare),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _shareFile();
                },
              ),
              ListTile(
                leading: const Icon(Icons.delete_outline, color: AppColors.error),
                title: Text(
                  l10n.viewerMenuDelete,
                  style: const TextStyle(color: AppColors.error),
                ),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _delete();
                },
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _download() async {
    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(filesRepositoryProvider);
    final token = await ref.read(tokenStorageProvider).readToken();
    final url = repo.downloadUrl(widget.fileId, token: token, inline: false);
    try {
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/${widget.filename}');
      final res = await ref.read(apiClientProvider).dio.download(
            url,
            file.path,
          );
      if (res.statusCode == 200) {
        await OpenFilex.open(file.path);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(l10n.filesDownloadStarted)),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.filesDownloadFailed)),
        );
      }
    }
  }

  Future<void> _rename() async {
    final newName = await showDialog<String>(
      context: context,
      builder: (_) => RenameDialog(currentName: widget.filename),
    );
    if (newName == null || newName.isEmpty || newName == widget.filename) return;
    try {
      await ref
          .read(filesRepositoryProvider)
          .renameFile(widget.fileId, newName);
      if (mounted) {
        // Replace current screen with updated name in title.
        context.pushReplacement(
          '/viewer/${widget.fileId}',
          extra: {'filename': newName, 'mime': widget.mime},
        );
      }
    } catch (e) {
      if (!mounted) return;
      final l10n = AppLocalizations.of(context)!;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.filesRenameFailed)),
      );
    }
  }

  Future<void> _shareFile() async {
    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(filesRepositoryProvider);
    try {
      await repo.copyFileToClipboard(
        widget.fileId,
        filename: widget.filename,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.filesCopySuccess)),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.filesCopyFailed)),
        );
      }
    }
  }

  /// "Salin" — copy the share link to clipboard directly, no popup.
  /// Creates the share link if it doesn't exist, then copies the URL.
  Future<void> _copyLink() async {
    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(filesRepositoryProvider);
    try {
      final res = await repo.createShareLink(widget.fileId);
      // Backend returns { share_token, share_url }. Prefer share_url
      // (already includes the public host) and fall back to building
      // it locally from the token.
      String? url = res['share_url'] as String?;
      if (url == null) {
        final token = res['share_token'] as String?;
        if (token != null) {
          url = 'https://enstorage.enpii.studio/s/$token';
        }
      }
      if (url == null) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(l10n.filesCopyFailed)),
          );
        }
        return;
      }
      await Clipboard.setData(ClipboardData(text: url));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.shareCopied)),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.filesCopyFailed)),
        );
      }
    }
  }

  Future<void> _delete() async {
    final l10n = AppLocalizations.of(context)!;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(l10n.filesConfirmDeleteTitle),
        content: Text(l10n.filesConfirmDeleteBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(l10n.commonCancel),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.error),
            child: Text(l10n.filesConfirmDeleteConfirm),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await ref.read(filesRepositoryProvider).deleteFile(widget.fileId);
      if (mounted) context.pop();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.filesDeleteFailed)),
      );
    }
  }
}

// ─── Viewer widgets ─────────────────────────────────────────────────────

class _ImageViewer extends ConsumerWidget {
  const _ImageViewer({required this.fileId, required this.repo});
  final String fileId;
  final FilesRepository repo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final token = ref.watch(tokenStorageProvider).readTokenSync();
    return PhotoView(
      imageProvider: NetworkImage(
        repo.downloadUrl(fileId, token: token, inline: true),
        headers: token != null ? {'Authorization': 'Bearer $token'} : null,
      ),
      backgroundDecoration: const BoxDecoration(color: Colors.black),
      // Image is rendered at its native resolution. `contained` fits
      // the whole image inside the viewport (no cropping, no stretch)
      // and acts as the starting zoom level. The user can still
      // double-tap to zoom up to 4x for pixel-peeping.
      minScale: PhotoViewComputedScale.contained,
      initialScale: PhotoViewComputedScale.contained,
      maxScale: PhotoViewComputedScale.covered * 4,
      loadingBuilder: (_, __) =>
          const Center(child: CircularProgressIndicator()),
      errorBuilder: (_, __, ___) => const Center(
        child: Text('Failed', style: TextStyle(color: Colors.white)),
      ),
    );
  }
}

class _VideoViewer extends StatelessWidget {
  const _VideoViewer({required this.ctrl, required this.error});
  final VideoPlayerController? ctrl;
  final String? error;

  @override
  Widget build(BuildContext context) {
    if (error != null) {
      return Center(child: Text(error!, style: const TextStyle(color: Colors.white)));
    }
    if (ctrl == null || !ctrl!.value.isInitialized) {
      return const Center(child: CircularProgressIndicator());
    }
    return Center(
      child: AspectRatio(
        aspectRatio: ctrl!.value.aspectRatio,
        child: Stack(
          alignment: Alignment.bottomCenter,
          children: [
            VideoPlayer(ctrl!),
            VideoProgressIndicator(ctrl!, allowScrubbing: true),
            _PlayPauseOverlay(ctrl: ctrl!),
          ],
        ),
      ),
    );
  }
}

class _AudioViewer extends ConsumerStatefulWidget {
  const _AudioViewer({required this.fileId, required this.filename, required this.repo});
  final String fileId;
  final String filename;
  final FilesRepository repo;

  @override
  ConsumerState<_AudioViewer> createState() => _AudioViewerState();
}

class _AudioViewerState extends ConsumerState<_AudioViewer> {
  AudioPlayerWrap? _player;
  bool _playing = false;

  @override
  void initState() {
    super.initState();
    _player = AudioPlayerWrap(
      widget.fileId,
      widget.repo,
      ref.read(tokenStorageProvider),
    );
  }

  @override
  void dispose() {
    _player?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 120,
              height: 120,
              decoration: const BoxDecoration(
                color: AppColors.primaryContainer,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.music_note,
                  size: 64, color: AppColors.onPrimaryContainer),
            ),
            const SizedBox(height: 24),
            Text(
              widget.filename,
              style: AppTypography.bodyLg,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),
            IconButton(
              iconSize: 64,
              color: Colors.white,
              icon: Icon(_playing ? Icons.pause_circle : Icons.play_circle),
              onPressed: () async {
                if (_playing) {
                  await _player?.pause();
                } else {
                  await _player?.play();
                }
                if (mounted) setState(() => _playing = !_playing);
              },
            ),
          ],
        ),
      ),
    );
  }
}

/// Audio playback wrapper. Uses a hidden video player as a fallback
/// (avoids extra package). In a future revision, swap to `audioplayers`.
class AudioPlayerWrap {
  AudioPlayerWrap(this.fileId, this.repo, this.tokens);
  final String fileId;
  final FilesRepository repo;
  final TokenStorage tokens;
  // No-op shim — replace with `audioplayers` package in a follow-up.
  Future<void> play() async {}
  Future<void> pause() async {}
  void dispose() {}
}

class _PdfViewer extends ConsumerWidget {
  const _PdfViewer({required this.fileId, required this.repo});
  final String fileId;
  final FilesRepository repo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // PDF rendering needs a dedicated package (e.g. flutter_pdfview).
    // For now, show a placeholder + Download button.
    return _OtherViewer(
      fileId: fileId,
      filename: 'PDF',
      mime: 'application/pdf',
      repo: repo,
    );
  }
}

class _TextViewer extends StatelessWidget {
  const _TextViewer({required this.content});
  final String content;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.background,
      padding: const EdgeInsets.all(16),
      child: SingleChildScrollView(
        child: SelectableText(
          content,
          style: AppTypography.bodySm.copyWith(
            fontFamily: 'monospace',
            color: AppColors.onSurface,
          ),
        ),
      ),
    );
  }
}

class _OtherViewer extends ConsumerWidget {
  const _OtherViewer({
    required this.fileId,
    required this.filename,
    required this.mime,
    required this.repo,
  });
  final String fileId;
  final String filename;
  final String mime;
  final FilesRepository repo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.description, size: 64, color: AppColors.onSurfaceVariant),
            const SizedBox(height: 16),
            Text(filename, style: AppTypography.bodyLg),
            const SizedBox(height: 8),
            Text(mime, style: AppTypography.bodySm),
            const SizedBox(height: 24),
            EthericButton(
              label: 'Download',
              onPressed: () async {
                final token = ref.read(tokenStorageProvider).readTokenSync();
                final url = repo.downloadUrl(fileId, token: token, inline: false);
                await Clipboard.setData(ClipboardData(text: url));
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _PlayPauseOverlay extends StatefulWidget {
  const _PlayPauseOverlay({required this.ctrl});
  final VideoPlayerController ctrl;

  @override
  State<_PlayPauseOverlay> createState() => _PlayPauseOverlayState();
}

class _PlayPauseOverlayState extends State<_PlayPauseOverlay> {
  @override
  void initState() {
    super.initState();
    widget.ctrl.addListener(_onTick);
  }

  void _onTick() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    widget.ctrl.removeListener(_onTick);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.ctrl.value.isPlaying) return const SizedBox.shrink();
    return GestureDetector(
      onTap: () => widget.ctrl.play(),
      child: Container(
        color: Colors.black26,
        child: const Center(
          child: Icon(Icons.play_arrow, size: 80, color: Colors.white),
        ),
      ),
    );
  }
}
