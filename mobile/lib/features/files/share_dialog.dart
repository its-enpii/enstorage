import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/models/file_item.dart';
import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_button.dart';

/// Modal that creates / revokes a public share link for a file.
/// Mirrors the web `ShareDialog` component.
class ShareDialog extends ConsumerStatefulWidget {
  const ShareDialog({super.key, required this.file});

  final FileItem file;

  @override
  ConsumerState<ShareDialog> createState() => _ShareDialogState();
}

class _ShareDialogState extends ConsumerState<ShareDialog> {
  late String? _shareToken = widget.file.shareToken;
  bool _loading = false;
  bool _copied = false;

  String get _shareUrl {
    if (_shareToken == null) return '';
    // Web app handles public shares at /s/{token}. The mobile app can
    // hand the link off to the system share sheet rather than opening
    // it in-app, so the host doesn't matter.
    return 'https://enstorage.enpii.studio/s/$_shareToken';
  }

  Future<void> _enable() async {
    setState(() => _loading = true);
    try {
      final res = await ref
          .read(filesRepositoryProvider)
          .createShareLink(widget.file.id);
      setState(() => _shareToken = res['share_token'] as String?);
    } catch (_) {
      // surface a generic error — the backend message is already shown
      // by the global error listener in the host page.
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _disable() async {
    setState(() => _loading = true);
    try {
      await ref
          .read(filesRepositoryProvider)
          .deleteShareLink(widget.file.id);
      setState(() => _shareToken = null);
    } catch (_) {
      // ignore
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: _shareUrl));
    setState(() => _copied = true);
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) setState(() => _copied = false);
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;
    final hasShare = _shareToken != null;
    return Dialog(
      backgroundColor: scheme.surfaceContainer,
      shape: RoundedRectangleBorder(borderRadius: AppRadii.cardBorder),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.innerPadding),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  hasShare ? Icons.link : Icons.link_off,
                  color: hasShare ? scheme.primary : scheme.onSurfaceVariant,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    l10n.shareTitle,
                    style: AppTypography.headlineLgMobile,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              hasShare ? l10n.shareDescEnabled : l10n.shareDescDisabled,
              style: AppTypography.bodyMd.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            if (hasShare) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: scheme.surfaceContainerHigh,
                  borderRadius: AppRadii.controlBorder,
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        _shareUrl,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySm.copyWith(
                          fontFamily: 'monospace',
                          color: scheme.onSurface,
                        ),
                      ),
                    ),
                    IconButton(
                      icon: Icon(
                        _copied ? Icons.check : Icons.content_copy,
                        color: scheme.primary,
                        size: 20,
                      ),
                      onPressed: _copy,
                      tooltip: l10n.shareCopy,
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: hasShare
                      ? EthericButton(
                          label: l10n.shareDisable,
                          variant: EthericButtonVariant.danger,
                          onPressed: _loading ? null : _disable,
                          expanded: true,
                        )
                      : EthericButton(
                          label: l10n.commonCancel,
                          variant: EthericButtonVariant.secondary,
                          onPressed: () => Navigator.of(context).pop(),
                          expanded: true,
                        ),
                ),
                if (hasShare) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: EthericButton(
                      label: _copied ? l10n.shareCopied : l10n.shareCopyLink,
                      icon: _copied ? Icons.check : Icons.content_copy,
                      onPressed: _copy,
                      expanded: true,
                    ),
                  ),
                ] else ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: EthericButton(
                      label: l10n.shareCreateLink,
                      onPressed: _loading ? null : _enable,
                      loading: _loading,
                      expanded: true,
                    ),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}
