import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/api_client.dart' show kApiBase;
import '../../data/models/file_item.dart';
import '../../data/models/folder.dart';
import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/radii.dart';
import '../../theme/typography.dart';
import '../../widgets/app_dialog.dart';
import '../../widgets/etheric_button.dart';

/// Discriminated target for the share dialog. Either a file or a folder —
/// both can be shared by link with the same UI but different backend routes.
sealed class ShareTarget {
  const ShareTarget();
  String get id;
  String? get shareToken;
}

class ShareFileTarget extends ShareTarget {
  const ShareFileTarget(this.file);
  final FileItem file;

  @override
  String get id => file.id;
  @override
  String? get shareToken => file.shareToken;
}

class ShareFolderTarget extends ShareTarget {
  const ShareFolderTarget(this.folder);
  final Folder folder;

  @override
  String get id => folder.id;
  @override
  String? get shareToken => folder.shareToken;
}

/// Modal that creates / revokes a public share link for a file or folder.
/// Mirrors the web `ShareDialog` component.
///
/// Returned from [showAppDialog] — do not wrap in another `Dialog`,
/// the outer shell is provided there.
class ShareDialog extends ConsumerStatefulWidget {
  const ShareDialog({super.key, required this.target});

  final ShareTarget target;

  @override
  ConsumerState<ShareDialog> createState() => _ShareDialogState();
}

class _ShareDialogState extends ConsumerState<ShareDialog> {
  late String? _shareToken = widget.target.shareToken;
  // Full share URL as returned by the backend (POST /files/{id}/share
  // or POST /folders/{id}/share). Null when the link is freshly
  // enabled and we still need to fetch it, or when only the token was
  // supplied up-front (existing share). We always prefer the backend's
  // URL so the domain matches whatever the backend is configured for.
  String? _shareUrlFromBackend;
  bool _loading = false;
  bool _copied = false;

  bool get _isFolder => widget.target is ShareFolderTarget;

  /// Origin of the configured API base — used only as a fallback when
  /// the backend hasn't returned a full share URL (e.g. link was
  /// pre-existing and no fresh POST was made). Strips trailing slash
  /// and the `/api/v1` segment so we end up at the web origin.
  String _apiOrigin() {
    final base = kApiBase.endsWith('/')
        ? kApiBase.substring(0, kApiBase.length - 1)
        : kApiBase;
    // Drop `/api/v1` if present — server serves the public share page
    // at the web origin, not the API.
    return base.replaceFirst(RegExp(r'/api/v[0-9]+/?$'), '');
  }

  String get _shareUrl {
    final backend = _shareUrlFromBackend;
    if (backend != null && backend.isNotEmpty) return backend;
    if (_shareToken == null) return '';
    return '${_apiOrigin()}/s/$_shareToken';
  }

  Future<void> _enable() async {
    setState(() => _loading = true);
    try {
      final repo = ref.read(filesRepositoryProvider);
      final res = _isFolder
          ? await repo.createFolderShareLink(widget.target.id)
          : await repo.createShareLink(widget.target.id);
      // Backend now returns both share_token and share_url — prefer
      // share_url since it matches whatever host the backend is
      // currently serving from.
      final token = res['share_token'] as String?;
      final url = res['share_url'] as String?;
      setState(() {
        _shareToken = token;
        _shareUrlFromBackend = url;
      });
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
      final repo = ref.read(filesRepositoryProvider);
      if (_isFolder) {
        await repo.deleteFolderShareLink(widget.target.id);
      } else {
        await repo.deleteShareLink(widget.target.id);
      }
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
    final title = _isFolder ? l10n.shareFolderTitle : l10n.shareTitle;
    final desc = hasShare
        ? (_isFolder ? l10n.shareFolderDescEnabled : l10n.shareDescEnabled)
        : (_isFolder ? l10n.shareFolderDescDisabled : l10n.shareDescDisabled);
    return AppDialogBody(
      title: Row(
        children: [
          Icon(
            hasShare ? Icons.link : Icons.link_off,
            color: hasShare ? scheme.primary : scheme.onSurfaceVariant,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(title, style: AppTypography.headlineLgMobile),
          ),
        ],
      ),
      body: Text(
        desc,
        style: AppTypography.bodyMd.copyWith(color: scheme.onSurfaceVariant),
      ),
      actions: hasShare
          ? [
              EthericButton(
                label: l10n.shareDisable,
                variant: EthericButtonVariant.danger,
                onPressed: _loading ? null : _disable,
              ),
              EthericButton(
                label: _copied ? l10n.shareCopied : l10n.shareCopyLink,
                icon: _copied ? Icons.check : Icons.content_copy,
                onPressed: _copy,
              ),
            ]
          : [
              EthericButton(
                label: l10n.commonCancel,
                variant: EthericButtonVariant.secondary,
                onPressed: () => Navigator.of(context).pop(),
              ),
              EthericButton(
                label: l10n.shareCreateLink,
                onPressed: _loading ? null : _enable,
                loading: _loading,
              ),
            ],
      children: hasShare
          ? [
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 10,
                ),
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
            ]
          : const [],
    );
  }
}