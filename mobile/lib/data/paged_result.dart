/// Result of a paginated API call. Mirrors the backend's
/// `{ data, meta: { pagination: { page, last_page, per_page, total } } }`
/// envelope.
class PagedResult<T> {
  const PagedResult({
    required this.items,
    required this.page,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  final List<T> items;
  final int page;
  final int lastPage;
  final int perPage;
  final int total;

  bool get hasMore => page < lastPage;
}
