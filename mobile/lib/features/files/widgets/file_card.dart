import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/file_item.dart';
import '../../../data/repositories/files_repository.dart';
import '../../../data/storage/token_storage.dart';
import '../../../state/files_state.dart';
import '../../../state/selection_state.dart';
import '../../../widgets/etheric_card.dart';

class FileCard extends ConsumerWidget {
  const FileCard({
    super.key,
    required this.file,
    required this.onTap,
    required this.onLongPress,
    this.onOverflowTap,
    this.parentFolderId,
  });

  final FileItem file;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback? onOverflowTap;
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

    // Indikator visual: cukup icon star filled vs outline, tanpa border/tint
    // di kartu. Selected state (sudah ada di EthericCard) handle highlight
    // via outset ring primary glow.
    final isStarred = file.isStarred;

    return EthericCard(
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
      padding: const EdgeInsets.all(16),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: iconContent,
                ),
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
          // Star button di pojok kanan-atas card.
          Positioned(
            top: -4,
            right: -4,
            child: _StarButton(
              file: file,
              isStarred: isStarred,
              parentFolderId: parentFolderId,
            ),
          ),
          // Overflow button (3 dots) pojok kanan-bawah — trigger menu
          // (move to…). Disembunyikan ketika card lagi dalam selection mode
          // (parent udah pass onOverflowTap=null) supaya gak ganggu UX.
          if (onOverflowTap != null)
            Positioned(
              bottom: 0,
              right: 0,
              child: _OverflowButton(onTap: onOverflowTap!),
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

class _StarButton extends ConsumerWidget {
  const _StarButton({
    required this.file,
    required this.isStarred,
    required this.parentFolderId,
  });
  final FileItem file;
  final bool isStarred;
  final String? parentFolderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: isStarred
          ? scheme.primary
          : Colors.black.withValues(alpha: 0.5),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: () async {
          try {
            final updated = await ref
                .read(filesRepositoryProvider)
                .toggleStarFile(file.id, !file.isStarred);
            // Update state lokal — UI langsung berubah tanpa nunggu reload.
            // ignore: discarded_futures
            ref
                .read(filesControllerProvider(parentFolderId).notifier)
                .replaceFile(updated);
          } catch (_) {
            // ignore — surface error in a follow-up
          }
        },
        child: Padding(
          padding: const EdgeInsets.all(6),
          child: Icon(
            isStarred ? Icons.star_rounded : Icons.star_border_rounded,
            color: Colors.white,
            size: 18,
          ),
        ),
      ),
    );
  }
}

/// Overflow "..." button — trigger menu action (saat ini: move to…).
/// Tetap kelihatan subtle (low opacity) supaya gak ganggu visual card.
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
        child: Padding(
          padding: const EdgeInsets.all(6),
          child: Icon(
            Icons.more_vert,
            color: scheme.onSurface,
            size: 18,
          ),
        ),
      ),
    );
  }
}
