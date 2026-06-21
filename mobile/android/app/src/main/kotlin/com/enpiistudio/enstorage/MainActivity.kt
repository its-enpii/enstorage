package com.enpiistudio.enstorage

import io.flutter.embedding.android.FlutterFragmentActivity

/// `google_sign_in_android` 7.x uses Android Credential Manager under
/// the hood, which is `Fragment`-based. The host activity MUST extend
/// `FlutterFragmentActivity` (not `FlutterActivity`) or the SDK will
/// throw `IllegalStateException` at sign-in time.
///
/// See: https://pub.dev/packages/google_sign_in_android#integration
class MainActivity : FlutterFragmentActivity()
