/// Network mode selector untuk Auto Backup. Mapping string dipakai
/// di SharedPreferences supaya future enum members tidak break old data.
enum BackupNetworkMode { wifiOnly, wifiAndMobile, off }

BackupNetworkMode parseBackupNetworkMode(String? raw) {
  switch (raw) {
    case 'wifi_only':
      return BackupNetworkMode.wifiOnly;
    case 'wifi_and_mobile':
      return BackupNetworkMode.wifiAndMobile;
    case 'off':
      return BackupNetworkMode.off;
    default:
      return BackupNetworkMode.off;
  }
}

String backupNetworkModeToString(BackupNetworkMode m) {
  switch (m) {
    case BackupNetworkMode.wifiOnly:
      return 'wifi_only';
    case BackupNetworkMode.wifiAndMobile:
      return 'wifi_and_mobile';
    case BackupNetworkMode.off:
      return 'off';
  }
}

/// Preset interval check yang tersedia di Settings UI.
/// `custom` reserved untuk future expansion; saat ini UI cuma expose
/// preset (lihat `auto_backup_settings_screen.dart`).
enum BackupInterval { oneHour, sixHours, twelveHours, twentyFourHours }

const Map<BackupInterval, int> backupIntervalHours = {
  BackupInterval.oneHour: 1,
  BackupInterval.sixHours: 6,
  BackupInterval.twelveHours: 12,
  BackupInterval.twentyFourHours: 24,
};

BackupInterval parseBackupInterval(String? raw) {
  switch (raw) {
    case '1':
      return BackupInterval.oneHour;
    case '6':
      return BackupInterval.sixHours;
    case '12':
      return BackupInterval.twelveHours;
    case '24':
      return BackupInterval.twentyFourHours;
    default:
      return BackupInterval.sixHours;
  }
}

String backupIntervalToString(BackupInterval i) {
  return backupIntervalHours[i].toString();
}

/// Snapshot pengaturan Auto Backup yang disimpan di SharedPreferences.
/// `lastRunAt` opsional — null = belum pernah jalan.
class BackupSettings {
  const BackupSettings({
    required this.enabled,
    required this.mode,
    required this.interval,
    required this.requiresCharging,
    required this.batteryOptimizationExempted,
    this.lastRunAt,
    this.lastError,
  });

  final bool enabled;
  final BackupNetworkMode mode;
  final BackupInterval interval;
  final bool requiresCharging;
  final bool batteryOptimizationExempted;
  final DateTime? lastRunAt;
  final String? lastError;

  /// Settings default: OFF, mode off, interval 6h.
  static const initial = BackupSettings(
    enabled: false,
    mode: BackupNetworkMode.off,
    interval: BackupInterval.sixHours,
    requiresCharging: false,
    batteryOptimizationExempted: false,
  );

  BackupSettings copyWith({
    bool? enabled,
    BackupNetworkMode? mode,
    BackupInterval? interval,
    bool? requiresCharging,
    bool? batteryOptimizationExempted,
    DateTime? lastRunAt,
    bool clearLastRunAt = false,
    String? lastError,
    bool clearLastError = false,
  }) {
    return BackupSettings(
      enabled: enabled ?? this.enabled,
      mode: mode ?? this.mode,
      interval: interval ?? this.interval,
      requiresCharging: requiresCharging ?? this.requiresCharging,
      batteryOptimizationExempted:
          batteryOptimizationExempted ?? this.batteryOptimizationExempted,
      lastRunAt: clearLastRunAt ? null : (lastRunAt ?? this.lastRunAt),
      lastError: clearLastError ? null : (lastError ?? this.lastError),
    );
  }

  Map<String, Object?> toJson() => {
        'enabled': enabled,
        'mode': backupNetworkModeToString(mode),
        'interval': backupIntervalToString(interval),
        'requires_charging': requiresCharging,
        'battery_optimization_exempted': batteryOptimizationExempted,
        'last_run_at': lastRunAt?.toIso8601String(),
        'last_error': lastError,
      };

  factory BackupSettings.fromJson(Map<String, Object?> json) => BackupSettings(
        enabled: (json['enabled'] ?? false) as bool,
        mode: parseBackupNetworkMode(json['mode'] as String?),
        interval: parseBackupInterval(json['interval'] as String?),
        requiresCharging: (json['requires_charging'] ?? false) as bool,
        batteryOptimizationExempted:
            (json['battery_optimization_exempted'] ?? false) as bool,
        lastRunAt: json['last_run_at'] is String
            ? DateTime.tryParse(json['last_run_at'] as String)
            : null,
        lastError: json['last_error'] as String?,
      );
}