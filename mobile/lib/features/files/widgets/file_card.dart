import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/file_item.dart';
import '../../../data/repositories/files_repository.dart';
import '../../../data/storage/token_storage.dart';
import '../../../state/selection_state.dart';
import '../../../theme/colors.dart';
import '../../../theme/typography.dart';
import '../../../widgets/etheric_card.dart';

class FileCard extends ConsumerWidget {
  const FileCard({
    super.key,
    required this.file,
    required this.onTap,
    required this.onLongPress,
    this.onOverflowTap,
  });

  final FileItem file;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback? onOverflowTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(selectionControllerProvider).contains(file.id);
    final repo = ref.watch(filesRepositoryProvider);

    final token = ref.watch(tokenStorageProvider).readTokenSync();
    final hasThumb = file.hasThumbnail && file.uploadStatus == UploadStatus.done;
    final imageUrl = hasThumb ? repo.thumbnailUrl(file.id, token: token) : null;

    final iconContent = hasThumb
        ? CachedNetworkImage(
            imageUrl: imageUrl!,
            width: 56,
            height: 56,
            fit: BoxFit.cover,
            placeholder: (_, __) => _iconFallback(file.mimeType),
            errorWidget: (_, __, ___) => _iconFallback(file.mimeType),
          )
        : _iconFallback(file.mimeType);

    return EthericCard(
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: iconContent,
                ),
              ),
              Positioned(
                top: -4,
                right: -4,
                child: _StarButton(file: file),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Spacer(),
          Text(
            file.name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: AppColors.onSurface,
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
            style: const TextStyle(
              color: AppColors.onSurfaceVariant,
              fontSize: 13,
              fontWeight: FontWeight.w400,
              height: 1.3,
            ),
          ),
        ],
      ),
    );
  }

  Widget _iconFallback(String mime) {
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
        color: AppColors.primaryContainer,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Icon(icon, color: AppColors.onPrimaryContainer, size: 32),
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

class _StarButton extends ConsumerWidget {
  const _StarButton({required this.file});
  final FileItem file;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Material(
      color: Colors.black.withValues(alpha: 0.4),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: () async {
          try {
            await ref
                .read(filesRepositoryProvider)
                .toggleStarFile(file.id, !file.isStarred);
          } catch (_) {
            // ignore — surface error in a follow-up
          }
        },
        child: const Padding(
          padding: EdgeInsets.all(4),
          child: Icon(
            Icons.star_border_rounded,
            color: Colors.white,
            size: 14,
          ),
        ),
      ),
    );
  }
}
