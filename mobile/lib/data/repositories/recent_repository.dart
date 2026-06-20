import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client.dart';
import '../models/recent_entry.dart';

class RecentPage {
  const RecentPage({required this.items, required this.nextCursor});
  final List<RecentEntry> items;

  /// Pass back to [RecentRepository.fetchRecent] to load the next page.
  /// Null when there are no more items.
  final String? nextCursor;
}

class RecentRepository {
  RecentRepository(this._api);
  final ApiClient _api;

  /// Fetch a page of root-level folders + files, sorted by `updated_at`
  /// desc. Pass [cursor] from a previous response to load the next page.
  Future<RecentPage> fetchRecent({int limit = 30, String? cursor}) async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/recent',
      queryParameters: {
        'limit': limit,
        if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
      },
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    final rawItems = (inner['items'] as List?) ?? const [];
    final items = rawItems
        .whereType<Map<String, dynamic>>()
        .map(RecentEntry.fromJson)
        .toList();
    final nextCursor = inner['next_cursor'] as String?;
    return RecentPage(items: items, nextCursor: nextCursor);
  }
}

final recentRepositoryProvider = Provider<RecentRepository>((ref) {
  return RecentRepository(ref.watch(apiClientProvider));
});
