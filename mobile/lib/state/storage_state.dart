import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/api_client.dart';
import '../data/models/storage_summary.dart';

/// Storage summary across all connected Google accounts for the current
/// user. Backed by `GET /storage/summary`.
///
/// Callers can invalidate this provider after a Google account is
/// added / removed / quota-synced to force a fresh fetch.
final storageSummaryProvider =
    FutureProvider.autoDispose<StorageSummary?>((ref) async {
  final api = ref.watch(apiClientProvider);
  try {
    final res = await api.dio.get<Map<String, dynamic>>('/storage/summary');
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return StorageSummary.fromJson(inner);
  } catch (_) {
    return null;
  }
});
