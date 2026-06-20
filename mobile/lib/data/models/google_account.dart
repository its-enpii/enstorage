/// Mirrors backend `GoogleAccountResource` (snake_case JSON → camelCase Dart).
class GoogleAccountQuota {
  const GoogleAccountQuota({
    required this.total,
    required this.used,
    required this.free,
    this.syncedAt,
  });
  final int total;
  final int used;
  final int free;
  final DateTime? syncedAt;

  factory GoogleAccountQuota.fromJson(Map<String, dynamic> json) =>
      GoogleAccountQuota(
        total: (json['total'] ?? 0) as int,
        used: (json['used'] ?? 0) as int,
        free: (json['free'] ?? 0) as int,
        syncedAt: _parseDate(json['synced_at']),
      );

  static DateTime? _parseDate(dynamic v) {
    if (v is String && v.isNotEmpty) return DateTime.tryParse(v);
    return null;
  }
}

class GoogleAccount {
  const GoogleAccount({
    required this.id,
    required this.label,
    required this.email,
    required this.gdriveRootFolderId,
    required this.isActive,
    required this.quota,
    this.tokenExpiresAt,
    this.quotaSyncedAt,
    this.createdAt,
  });
  final String id;
  final String label;
  final String email;
  final String gdriveRootFolderId;
  final bool isActive;
  final GoogleAccountQuota quota;
  final DateTime? tokenExpiresAt;
  final DateTime? quotaSyncedAt;
  final DateTime? createdAt;

  factory GoogleAccount.fromJson(Map<String, dynamic> json) {
    return GoogleAccount(
      id: (json['id'] ?? '').toString(),
      label: (json['label'] ?? '') as String,
      email: (json['email'] ?? '') as String,
      gdriveRootFolderId: (json['gdrive_root_folder_id'] ?? '') as String,
      isActive: (json['is_active'] ?? false) as bool,
      quota: GoogleAccountQuota.fromJson(
        (json['quota'] as Map<String, dynamic>?) ?? const {},
      ),
      tokenExpiresAt: _parseDate(json['token_expires_at']),
      quotaSyncedAt: _parseDate(json['quota_synced_at']),
      createdAt: _parseDate(json['created_at']),
    );
  }

  static DateTime? _parseDate(dynamic v) {
    if (v is String && v.isNotEmpty) return DateTime.tryParse(v);
    return null;
  }
}
