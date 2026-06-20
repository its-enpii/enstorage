/// Backend response for `/auth/login`, `/auth/register`, `/auth/me`.
class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    this.locale = 'id',
  });

  final String id;
  final String name;
  final String email;
  final String locale;

  factory User.fromJson(Map<String, dynamic> json) => User(
        id: (json['id'] ?? '').toString(),
        name: (json['name'] ?? '') as String,
        email: (json['email'] ?? '') as String,
        locale: (json['locale'] ?? 'id') as String,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'locale': locale,
      };
}
