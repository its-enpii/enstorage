import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/file_item.dart';
import '../../../data/repositories/files_repository.dart';
import '../../../data/storage/token_storage.dart';
import '../../../state/selection_state.dart';
import '../../../widgets/etheric_card.dart';

class FileCard extends ConsumerWidget {
  const FileCard({
    super.key,
    required this.file,
    required this.onTap,
    required this.onLongPress,
    required this.onOverflowTap,
    this.parentFolderId,
  });

  final FileItem file;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback onOverflowTap;
  final String? parentFolderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(selectionControllerProvider).contains(file.id);
    final repo = ref.watch(filesRepositoryProvider);
    final scheme = Theme.of(context).colorScheme;

    final token = ref.watch(tokenStorageProvider).readTokenSync();
    final hasThumb = file.hasThumbnail && file.uploadStatus == UploadStatus.done;
    final imageUrl = hasThumb ? repo.thumbnailUrl(file.id, token: token) : null;

    final iconContent = hasThumb
        ? CachedNetworkImage(
            imageUrl: imageUrl!,
            width: 56,
            height: 56,
            fit: BoxFit.cover,
            placeholder: (_, __) => _iconFallback(file.mimeType, scheme),
            errorWidget: (_, __, ___) => _iconFallback(file.mimeType, scheme),
          )
        : _iconFallback(file.mimeType, scheme);

    return EthericCard(
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Header row: thumbnail di kiri, ⋮ di kanan. Row + Spacer
          // pattern sama dengan FolderCard's share icon — icon ⋮
          // selalu nempel ke pojok kanan, tidak ter-overlap nama file.
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: iconContent,
                ),
              ),
              const Spacer(),
              _OverflowButton(onTap: onOverflowTap),
            ],
          ),
          const SizedBox(height: 12),
          const Spacer(),
          Text(
            file.name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: scheme.onSurface,
              fontSize: 16,
              fontWeight: FontWeight.w600,
              height: 1.3,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            _humanSize(file.size),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: scheme.onSurfaceVariant,
              fontSize: 13,
              fontWeight: FontWeight.w400,
              height: 1.3,
            ),
          ),
        ],
      ),
    );
  }

  Widget _iconFallback(String mime, ColorScheme scheme) {
    final isImage = mime.startsWith('image/');
    final isVideo = mime.startsWith('video/');
    final isPdf = mime == 'application/pdf';
    final IconData icon;
    if (isImage) {
      icon = Icons.image_outlined;
    } else if (isVideo) {
      icon = Icons.movie_outlined;
    } else if (isPdf) {
      icon = Icons.picture_as_pdf_outlined;
    } else {
      icon = Icons.description_outlined;
    }
    return Container(
      width: 56,
      height: 56,
      decoration: BoxDecoration(
        color: scheme.primaryContainer,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Icon(icon, color: scheme.onPrimaryContainer, size: 32),
    );
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
}

/// Single overflow "..." button di kanan-atas Row. Tap → buka
/// bottom sheet dengan opsi Star, Move, Rename, Share, Download, Delete.
class _OverflowButton extends StatelessWidget {
  const _OverflowButton({required this.onTap});
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainerHigh.withValues(alpha: 0.85),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: const Padding(
          padding: EdgeInsets.all(6),
          child: Icon(
            Icons.more_vert,
            size: 18,
          ),
        ),
      ),
    );
  }
}
