/// Backend response for `/auth/login`, `/auth/register`, `/auth/me`.
class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    this.locale = 'id',
    this.clientKeys = const <String>[],
  });

  final String id;
  final String name;
  final String email;
  final String locale;

  /// Distinct client_keys owned by this user (one per unique device /
  /// install). Returned by `/auth/me?with_counts=1` so the realtime
  /// layer can subscribe to per-client_key Reverb channels. May be
  /// empty for users with no uploads yet.
  final List<String> clientKeys;

  factory User.fromJson(Map<String, dynamic> json) => User(
        id: (json['id'] ?? '').toString(),
        name: (json['name'] ?? '') as String,
        email: (json['email'] ?? '') as String,
        locale: (json['locale'] ?? 'id') as String,
        clientKeys: (json['client_keys'] as List?)
                ?.map((e) => e.toString())
                .toList() ??
            const <String>[],
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'locale': locale,
        'client_keys': clientKeys,
      };
}
