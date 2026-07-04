import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/api_client.dart' show kApiBase;
import '../../data/models/file_item.dart';
import '../../data/models/folder.dart';
import '../../data/models/share_link.dart';
import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
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

/// Modal untuk share file atau folder.
///
/// Dua mekanisme coexist:
/// - Legacy single-token (share_token di files/folders) untuk backward-compat
///   URL share yang sudah terlanjur keluar.
/// - New pivot (share_links table) untuk share link dengan expiry & max_views.
///   Multi-link per resource, masing-masing dengan batasan sendiri.
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
  String? _shareUrlFromBackend;
  bool _loading = false;
  bool _copied = false;

  // New share-link state (pivot).
  List<ShareLink> _links = const [];
  String? _copiedLinkId;
  String _expiryPreset = 'none'; // 'none' | '1h' | '1d' | '1w' | 'custom'
  DateTime? _customExpiry;
  final TextEditingController _maxViewsController = TextEditingController();

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

  @override
  void initState() {
    super.initState();
    _loadLinks();
  }

  @override
  void dispose() {
    _maxViewsController.dispose();
    super.dispose();
  }

  Future<void> _loadLinks() async {
    try {
      final repo = ref.read(filesRepositoryProvider);
      final links = _isFolder
          ? await repo.listFolderShareLinks(widget.target.id)
          : await repo.listFileShareLinks(widget.target.id);
      if (mounted) setState(() => _links = links);
    } catch (_) {
      // surface via global error listener
    }
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

  // ─── New share-link actions (pivot) ──────────────────────────

  DateTime? _resolveExpiry() {
    final now = DateTime.now();
    switch (_expiryPreset) {
      case '1h':
        return now.add(const Duration(hours: 1));
      case '1d':
        return now.add(const Duration(days: 1));
      case '1w':
        return now.add(const Duration(days: 7));
      case 'custom':
        return _customExpiry;
      default:
        return null;
    }
  }

  Future<void> _createAdvanced() async {
    setState(() => _loading = true);
    try {
      final repo = ref.read(filesRepositoryProvider);
      final expiresAt = _resolveExpiry();
      final maxViews = _maxViewsController.text.trim().isEmpty
          ? null
          : int.tryParse(_maxViewsController.text.trim());

      final link = _isFolder
          ? await repo.createFolderShareLinkWithLimits(
              widget.target.id,
              expiresAt: expiresAt,
              maxViews: maxViews,
            )
          : await repo.createFileShareLink(
              widget.target.id,
              expiresAt: expiresAt,
              maxViews: maxViews,
            );
      if (mounted) {
        setState(() {
          _links = [link, ..._links];
          _expiryPreset = 'none';
          _customExpiry = null;
          _maxViewsController.clear();
        });
      }
    } catch (_) {
      // global error listener
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _revokeLink(String id) async {
    setState(() => _loading = true);
    try {
      await ref.read(filesRepositoryProvider).revokeShareLink(id);
      if (mounted) {
        setState(() => _links = _links.where((l) => l.id != id).toList());
      }
    } catch (_) {
      // ignore
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _copyLink(String url, String id) async {
    await Clipboard.setData(ClipboardData(text: url));
    setState(() => _copiedLinkId = id);
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) setState(() => _copiedLinkId = null);
    });
  }

  String _formatExpiry(DateTime? iso) {
    if (iso == null) {
      return AppLocalizations.of(context)!.shareLinkPermanent;
    }
    final l10n = AppLocalizations.of(context)!;
    final diff = iso.difference(DateTime.now());
    if (diff.isNegative) {
      return l10n.shareLinkExpires(iso.toLocal().toString());
    }
    if (diff.inMinutes < 60) {
      return l10n.shareLinkExpiresIn('${diff.inMinutes}m');
    }
    if (diff.inHours < 24) {
      return l10n.shareLinkExpiresIn('${diff.inHours}h');
    }
    return l10n.shareLinkExpiresIn('${diff.inDays}d');
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
            (hasShare || _links.isNotEmpty) ? Icons.link : Icons.link_off,
            color: (hasShare || _links.isNotEmpty)
                ? scheme.primary
                : scheme.onSurfaceVariant,
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
      actions: hasShare || _links.isNotEmpty
          ? [
              if (hasShare)
                EthericButton(
                  label: l10n.shareDisable,
                  variant: EthericButtonVariant.danger,
                  onPressed: _loading ? null : _disable,
                ),
              EthericButton(
                label: l10n.commonCancel,
                variant: EthericButtonVariant.secondary,
                onPressed: () => Navigator.of(context).pop(),
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
      children: [
        // Legacy single-token row
        if (hasShare) ...[
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
          const SizedBox(height: AppSpacing.innerPadding),
        ],

        // New: advanced form untuk share link dengan expiry/max_views
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: scheme.surfaceContainerHigh,
            borderRadius: AppRadii.controlBorder,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _buildExpiryField(l10n, scheme),
              const SizedBox(height: 12),
              _buildMaxViewsField(l10n, scheme),
              const SizedBox(height: 12),
              EthericButton(
                label: l10n.shareCreateAdvanced,
                onPressed: _loading ? null : _createAdvanced,
                loading: _loading,
              ),
            ],
          ),
        ),

        const SizedBox(height: AppSpacing.innerPadding),

        // Active share links list
        _buildActiveLinksList(l10n, scheme),
      ],
    );
  }

  Widget _buildExpiryField(AppLocalizations l10n, ColorScheme scheme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.event, size: 16, color: scheme.onSurfaceVariant),
            const SizedBox(width: 8),
            Text(
              l10n.shareExpiryLabel,
              style: AppTypography.labelSm.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ),
        const SizedBox(height: 6),
        DropdownButton<String>(
          value: _expiryPreset,
          isExpanded: true,
          onChanged: _loading
              ? null
              : (v) {
                  if (v != null) setState(() => _expiryPreset = v);
                },
          items: [
            DropdownMenuItem(value: 'none', child: Text(l10n.shareExpiryNone)),
            DropdownMenuItem(value: '1h', child: Text(l10n.shareExpiryHour)),
            DropdownMenuItem(value: '1d', child: Text(l10n.shareExpiryDay)),
            DropdownMenuItem(value: '1w', child: Text(l10n.shareExpiryWeek)),
            DropdownMenuItem(value: 'custom', child: Text(l10n.shareExpiryCustom)),
          ],
        ),
        if (_expiryPreset == 'custom') ...[
          const SizedBox(height: 6),
          TextButton(
            onPressed: _loading ? null : _pickCustomDate,
            child: Text(
              _customExpiry == null
                  ? l10n.shareExpiryCustom
                  : _customExpiry!.toLocal().toString(),
            ),
          ),
        ],
      ],
    );
  }

  Future<void> _pickCustomDate() async {
    final now = DateTime.now();
    final pickedDate = await showDatePicker(
      context: context,
      initialDate: _customExpiry ?? now.add(const Duration(hours: 1)),
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
    );
    if (pickedDate == null || !mounted) return;
    final pickedTime = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.now(),
    );
    if (pickedTime == null || !mounted) return;
    setState(() {
      _customExpiry = DateTime(
        pickedDate.year,
        pickedDate.month,
        pickedDate.day,
        pickedTime.hour,
        pickedTime.minute,
      );
    });
  }

  Widget _buildMaxViewsField(AppLocalizations l10n, ColorScheme scheme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.visibility, size: 16, color: scheme.onSurfaceVariant),
            const SizedBox(width: 8),
            Text(
              l10n.shareMaxViewsLabel,
              style: AppTypography.labelSm.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ),
        const SizedBox(height: 6),
        TextField(
          enabled: !_loading,
          keyboardType: TextInputType.number,
          decoration: InputDecoration(
            hintText: l10n.shareMaxViewsNone,
            isDense: true,
          ),
          controller: _maxViewsController,
        ),
      ],
    );
  }

  Widget _buildActiveLinksList(AppLocalizations l10n, ColorScheme scheme) {
    if (_links.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Text(
          l10n.shareNoActiveLinks,
          textAlign: TextAlign.center,
          style: AppTypography.bodySm.copyWith(color: scheme.onSurfaceVariant),
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          l10n.shareActiveLinks,
          style: AppTypography.labelSm.copyWith(
            color: scheme.onSurfaceVariant,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 8),
        ..._links.map((link) => _buildLinkRow(link, l10n, scheme)),
      ],
    );
  }

  Widget _buildLinkRow(ShareLink link, AppLocalizations l10n, ColorScheme scheme) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHigh,
        borderRadius: AppRadii.controlBorder,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  link.url,
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
                  _copiedLinkId == link.id ? Icons.check : Icons.content_copy,
                  color: scheme.primary,
                  size: 18,
                ),
                onPressed: () => _copyLink(link.url, link.id),
                tooltip: l10n.shareCopy,
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(),
              ),
              const SizedBox(width: 8),
              TextButton(
                onPressed: _loading ? null : () => _revokeLink(link.id),
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  minimumSize: const Size(0, 32),
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
                child: Text(
                  l10n.shareLinkRevoke,
                  style: AppTypography.labelSm.copyWith(color: scheme.error),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Text(
                link.maxViews != null
                    ? l10n.shareLinkViews(link.viewsCount, link.maxViews!)
                    : l10n.shareLinkViewsUnlimited(link.viewsCount),
                style: AppTypography.metadata.copyWith(color: scheme.onSurfaceVariant),
              ),
              const SizedBox(width: 8),
              Text(
                '·',
                style: AppTypography.metadata.copyWith(color: scheme.onSurfaceVariant),
              ),
              const SizedBox(width: 8),
              Text(
                _formatExpiry(link.expiresAt),
                style: AppTypography.metadata.copyWith(color: scheme.onSurfaceVariant),
              ),
            ],
          ),
        ],
      ),
    );
  }
}