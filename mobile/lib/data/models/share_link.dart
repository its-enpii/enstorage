/// Mirrors backend `ShareLinkResource`. Polymorphic share link dengan
/// expiry & max_views — coexist dengan `share_token` legacy di
/// FileItem/Folder.
///
/// Status state machine (lihat backend ShareLink model):
///   - revoked_at != null        → REVOKED
///   - expires_at di masa lalu   → EXPIRED
///   - views_count >= max_views  → EXHAUSTED
///   - else                      → ACTIVE
class ShareLink {
  const ShareLink({
    required this.id,
    required this.token,
    required this.url,
    required this.previewUrl,
    required this.viewsCount,
    required this.isActive,
    required this.shareableType,
    required this.shareableId,
    required this.createdAt,
    this.expiresAt,
    this.maxViews,
    this.revokedAt,
  });

  final String id;
  final String token;
  final String url;
  final String previewUrl;
  final DateTime? expiresAt;
  final int? maxViews;
  final int viewsCount;
  final DateTime? revokedAt;
  final bool isActive;
  final String shareableType;
  final String shareableId;
  final DateTime createdAt;

  /// Computed: `is_active` di backend boleh stale (race antara
  /// resolveActive dan storage), jadi cek ulang di client.
  bool get currentlyActive {
    if (revokedAt != null) return false;
    if (expiresAt != null && expiresAt!.isBefore(DateTime.now())) return false;
    if (maxViews != null && viewsCount >= maxViews!) return false;
    return isActive;
  }

  factory ShareLink.fromJson(Map<String, dynamic> json) => ShareLink(
        id: (json['id'] ?? '').toString(),
        token: (json['token'] ?? '') as String,
        url: (json['url'] ?? '') as String,
        previewUrl: (json['preview_url'] ?? '') as String,
        expiresAt: _parseDate(json['expires_at']),
        maxViews: json['max_views'] as int?,
        viewsCount: (json['views_count'] ?? 0) as int,
        revokedAt: _parseDate(json['revoked_at']),
        isActive: (json['is_active'] ?? false) as bool,
        shareableType: (json['shareable_type'] ?? '') as String,
        shareableId: (json['shareable_id'] ?? '').toString(),
        createdAt: _parseDate(json['created_at']) ?? DateTime.now(),
      );

  static DateTime? _parseDate(dynamic raw) {
    if (raw == null) return null;
    if (raw is String && raw.isNotEmpty) return DateTime.tryParse(raw);
    return null;
  }
}