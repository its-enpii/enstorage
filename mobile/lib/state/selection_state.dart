import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Multi-select state — used by the Files screen.
/// Set semantics: O(1) add/remove/contains.
@immutable
class SelectionState {
  const SelectionState({this.ids = const <String>{}});
  final Set<String> ids;

  bool get isEmpty => ids.isEmpty;
  bool get isNotEmpty => ids.isNotEmpty;
  int get count => ids.length;
  bool contains(String id) => ids.contains(id);

  SelectionState copyWith({Set<String>? ids}) => SelectionState(ids: ids ?? this.ids);
}

class SelectionController extends StateNotifier<SelectionState> {
  SelectionController() : super(const SelectionState());

  void toggle(String id) {
    final next = {...state.ids};
    if (!next.add(id)) next.remove(id);
    state = state.copyWith(ids: next);
  }

  void clear() => state = const SelectionState();

  void selectAll(Iterable<String> ids) {
    state = state.copyWith(ids: ids.toSet());
  }
}

final selectionControllerProvider =
    StateNotifierProvider<SelectionController, SelectionState>(
  (ref) => SelectionController(),
);
