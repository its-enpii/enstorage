import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/models/folder.dart';
import '../../../l10n/gen/app_localizations.dart';
import '../../../state/selection_state.dart';
import '../../../theme/typography.dart';
import '../../../widgets/etheric_card.dart';

class FolderCard extends ConsumerWidget {
  const FolderCard({
    super.key,
    required this.folder,
    required this.onTap,
    required this.onLongPress,
    this.onShare,
  });

  final Folder folder;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback? onShare;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context)!;
    final selected = ref.watch(selectionControllerProvider).contains(folder.id);
    final items = folder.filesCount + folder.foldersCount;
    final scheme = Theme.of(context).colorScheme;
    return EthericCard(
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(
                  color: scheme.primaryContainer,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(
                  Icons.folder_rounded,
                  color: scheme.onPrimaryContainer,
                  size: 32,
                ),
              ),
              const Spacer(),
              if (onShare != null)
                IconButton(
                  visualDensity: VisualDensity.compact,
                  tooltip: l10n.filesActionsShare,
                  icon: Icon(
                    folder.shareToken != null
                        ? Icons.link
                        : Icons.share_outlined,
                    color: folder.shareToken != null
                        ? scheme.primary
                        : scheme.onSurfaceVariant,
                    size: 20,
                  ),
                  onPressed: onShare,
                ),
            ],
          ),
          const SizedBox(height: 12),
          const Spacer(),
          Text(
            folder.name,
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
            l10n.filesFolderCount(items),
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
}
