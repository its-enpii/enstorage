import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

@immutable
class UploadProgress {
  const UploadProgress({
    required this.id,
    required this.filename,
    required this.sent,
    required this.total,
  });

  final String id;
  final String filename;
  final int sent;
  final int total;

  int get percent => total == 0 ? 0 : ((sent / total) * 100).round();
  bool get isComplete => sent >= total;
}

class UploadController extends StateNotifier<List<UploadProgress>> {
  UploadController() : super(const []);

  String start(String filename) {
    final id = DateTime.now().microsecondsSinceEpoch.toString();
    state = [
      ...state,
      UploadProgress(id: id, filename: filename, sent: 0, total: 1),
    ];
    return id;
  }

  void update(String id, {int? sent, int? total}) {
    state = [
      for (final u in state)
        if (u.id == id)
          UploadProgress(
            id: u.id,
            filename: u.filename,
            sent: sent ?? u.sent,
            total: total ?? u.total,
          )
        else
          u,
    ];
  }

  void complete(String id) {
    state = state.where((u) => u.id != id).toList();
  }

  void fail(String id) {
    state = state.where((u) => u.id != id).toList();
  }
}

final uploadControllerProvider =
    StateNotifierProvider<UploadController, List<UploadProgress>>(
  (ref) => UploadController(),
);
