class AccountQuota {
  const AccountQuota({
    required this.total,
    required this.used,
    required this.free,
  });
  final int total;
  final int used;
  final int free;

  factory AccountQuota.fromJson(Map<String, dynamic> json) => AccountQuota(
        total: (json['total'] ?? 0) as int,
        used: (json['used'] ?? 0) as int,
        free: (json['free'] ?? 0) as int,
      );
}

class StorageAccount {
  const StorageAccount({
    required this.accountId,
    required this.email,
    required this.label,
    required this.quota,
  });
  final String accountId;
  final String email;
  final String label;
  final AccountQuota quota;

  factory StorageAccount.fromJson(Map<String, dynamic> json) => StorageAccount(
        accountId: (json['account_id'] ?? '') as String,
        email: (json['email'] ?? '') as String,
        label: (json['label'] ?? json['email'] ?? '') as String,
        quota: AccountQuota.fromJson(
          (json['quota'] as Map<String, dynamic>?) ?? const {},
        ),
      );
}

class StorageSummary {
  const StorageSummary({
    required this.total,
    required this.used,
    required this.free,
    required this.accountsCount,
    required this.accountsErrored,
    required this.breakdown,
  });
  final int total;
  final int used;
  final int free;
  final int accountsCount;
  final int accountsErrored;
  final List<StorageAccount> breakdown;

  factory StorageSummary.fromJson(Map<String, dynamic> json) => StorageSummary(
        total: (json['total'] ?? 0) as int,
        used: (json['used'] ?? 0) as int,
        free: (json['free'] ?? 0) as int,
        accountsCount: (json['accounts_count'] ?? 0) as int,
        accountsErrored: (json['accounts_errored'] ?? 0) as int,
        breakdown: ((json['breakdown'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(StorageAccount.fromJson)
            .toList(),
      );
}
