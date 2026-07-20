// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'backup_database.dart';

// ignore_for_file: type=lint
class $BackupQueueItemsTable extends BackupQueueItems
    with TableInfo<$BackupQueueItemsTable, BackupQueueItemEntry> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $BackupQueueItemsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _localAssetIdMeta =
      const VerificationMeta('localAssetId');
  @override
  late final GeneratedColumn<String> localAssetId = GeneratedColumn<String>(
      'local_asset_id', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _absolutePathMeta =
      const VerificationMeta('absolutePath');
  @override
  late final GeneratedColumn<String> absolutePath = GeneratedColumn<String>(
      'absolute_path', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _displayNameMeta =
      const VerificationMeta('displayName');
  @override
  late final GeneratedColumn<String> displayName = GeneratedColumn<String>(
      'display_name', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _sizeBytesMeta =
      const VerificationMeta('sizeBytes');
  @override
  late final GeneratedColumn<int> sizeBytes = GeneratedColumn<int>(
      'size_bytes', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _modifiedAtMsMeta =
      const VerificationMeta('modifiedAtMs');
  @override
  late final GeneratedColumn<int> modifiedAtMs = GeneratedColumn<int>(
      'modified_at_ms', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
      'status', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _progressBytesMeta =
      const VerificationMeta('progressBytes');
  @override
  late final GeneratedColumn<int> progressBytes = GeneratedColumn<int>(
      'progress_bytes', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _errorMessageMeta =
      const VerificationMeta('errorMessage');
  @override
  late final GeneratedColumn<String> errorMessage = GeneratedColumn<String>(
      'error_message', aliasedName, true,
      type: DriftSqlType.string, requiredDuringInsert: false);
  static const VerificationMeta _remoteFileIdMeta =
      const VerificationMeta('remoteFileId');
  @override
  late final GeneratedColumn<String> remoteFileId = GeneratedColumn<String>(
      'remote_file_id', aliasedName, true,
      type: DriftSqlType.string, requiredDuringInsert: false);
  static const VerificationMeta _contentHashMeta =
      const VerificationMeta('contentHash');
  @override
  late final GeneratedColumn<String> contentHash = GeneratedColumn<String>(
      'content_hash', aliasedName, true,
      type: DriftSqlType.string, requiredDuringInsert: false);
  static const VerificationMeta _targetRelativePathMeta =
      const VerificationMeta('targetRelativePath');
  @override
  late final GeneratedColumn<String> targetRelativePath =
      GeneratedColumn<String>('target_relative_path', aliasedName, true,
          type: DriftSqlType.string, requiredDuringInsert: false);
  @override
  List<GeneratedColumn> get $columns => [
        localAssetId,
        absolutePath,
        displayName,
        sizeBytes,
        modifiedAtMs,
        status,
        progressBytes,
        errorMessage,
        remoteFileId,
        contentHash,
        targetRelativePath
      ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'backup_queue_items';
  @override
  VerificationContext validateIntegrity(
      Insertable<BackupQueueItemEntry> instance,
      {bool isInserting = false}) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('local_asset_id')) {
      context.handle(
          _localAssetIdMeta,
          localAssetId.isAcceptableOrUnknown(
              data['local_asset_id']!, _localAssetIdMeta));
    } else if (isInserting) {
      context.missing(_localAssetIdMeta);
    }
    if (data.containsKey('absolute_path')) {
      context.handle(
          _absolutePathMeta,
          absolutePath.isAcceptableOrUnknown(
              data['absolute_path']!, _absolutePathMeta));
    } else if (isInserting) {
      context.missing(_absolutePathMeta);
    }
    if (data.containsKey('display_name')) {
      context.handle(
          _displayNameMeta,
          displayName.isAcceptableOrUnknown(
              data['display_name']!, _displayNameMeta));
    } else if (isInserting) {
      context.missing(_displayNameMeta);
    }
    if (data.containsKey('size_bytes')) {
      context.handle(_sizeBytesMeta,
          sizeBytes.isAcceptableOrUnknown(data['size_bytes']!, _sizeBytesMeta));
    } else if (isInserting) {
      context.missing(_sizeBytesMeta);
    }
    if (data.containsKey('modified_at_ms')) {
      context.handle(
          _modifiedAtMsMeta,
          modifiedAtMs.isAcceptableOrUnknown(
              data['modified_at_ms']!, _modifiedAtMsMeta));
    } else if (isInserting) {
      context.missing(_modifiedAtMsMeta);
    }
    if (data.containsKey('status')) {
      context.handle(_statusMeta,
          status.isAcceptableOrUnknown(data['status']!, _statusMeta));
    } else if (isInserting) {
      context.missing(_statusMeta);
    }
    if (data.containsKey('progress_bytes')) {
      context.handle(
          _progressBytesMeta,
          progressBytes.isAcceptableOrUnknown(
              data['progress_bytes']!, _progressBytesMeta));
    } else if (isInserting) {
      context.missing(_progressBytesMeta);
    }
    if (data.containsKey('error_message')) {
      context.handle(
          _errorMessageMeta,
          errorMessage.isAcceptableOrUnknown(
              data['error_message']!, _errorMessageMeta));
    }
    if (data.containsKey('remote_file_id')) {
      context.handle(
          _remoteFileIdMeta,
          remoteFileId.isAcceptableOrUnknown(
              data['remote_file_id']!, _remoteFileIdMeta));
    }
    if (data.containsKey('content_hash')) {
      context.handle(
          _contentHashMeta,
          contentHash.isAcceptableOrUnknown(
              data['content_hash']!, _contentHashMeta));
    }
    if (data.containsKey('target_relative_path')) {
      context.handle(
          _targetRelativePathMeta,
          targetRelativePath.isAcceptableOrUnknown(
              data['target_relative_path']!, _targetRelativePathMeta));
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {localAssetId};
  @override
  BackupQueueItemEntry map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return BackupQueueItemEntry(
      localAssetId: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}local_asset_id'])!,
      absolutePath: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}absolute_path'])!,
      displayName: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}display_name'])!,
      sizeBytes: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}size_bytes'])!,
      modifiedAtMs: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}modified_at_ms'])!,
      status: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}status'])!,
      progressBytes: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}progress_bytes'])!,
      errorMessage: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}error_message']),
      remoteFileId: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}remote_file_id']),
      contentHash: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}content_hash']),
      targetRelativePath: attachedDatabase.typeMapping.read(
          DriftSqlType.string, data['${effectivePrefix}target_relative_path']),
    );
  }

  @override
  $BackupQueueItemsTable createAlias(String alias) {
    return $BackupQueueItemsTable(attachedDatabase, alias);
  }
}

class BackupQueueItemEntry extends DataClass
    implements Insertable<BackupQueueItemEntry> {
  final String localAssetId;
  final String absolutePath;
  final String displayName;
  final int sizeBytes;
  final int modifiedAtMs;
  final String status;
  final int progressBytes;
  final String? errorMessage;
  final String? remoteFileId;
  final String? contentHash;
  final String? targetRelativePath;
  const BackupQueueItemEntry(
      {required this.localAssetId,
      required this.absolutePath,
      required this.displayName,
      required this.sizeBytes,
      required this.modifiedAtMs,
      required this.status,
      required this.progressBytes,
      this.errorMessage,
      this.remoteFileId,
      this.contentHash,
      this.targetRelativePath});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['local_asset_id'] = Variable<String>(localAssetId);
    map['absolute_path'] = Variable<String>(absolutePath);
    map['display_name'] = Variable<String>(displayName);
    map['size_bytes'] = Variable<int>(sizeBytes);
    map['modified_at_ms'] = Variable<int>(modifiedAtMs);
    map['status'] = Variable<String>(status);
    map['progress_bytes'] = Variable<int>(progressBytes);
    if (!nullToAbsent || errorMessage != null) {
      map['error_message'] = Variable<String>(errorMessage);
    }
    if (!nullToAbsent || remoteFileId != null) {
      map['remote_file_id'] = Variable<String>(remoteFileId);
    }
    if (!nullToAbsent || contentHash != null) {
      map['content_hash'] = Variable<String>(contentHash);
    }
    if (!nullToAbsent || targetRelativePath != null) {
      map['target_relative_path'] = Variable<String>(targetRelativePath);
    }
    return map;
  }

  BackupQueueItemsCompanion toCompanion(bool nullToAbsent) {
    return BackupQueueItemsCompanion(
      localAssetId: Value(localAssetId),
      absolutePath: Value(absolutePath),
      displayName: Value(displayName),
      sizeBytes: Value(sizeBytes),
      modifiedAtMs: Value(modifiedAtMs),
      status: Value(status),
      progressBytes: Value(progressBytes),
      errorMessage: errorMessage == null && nullToAbsent
          ? const Value.absent()
          : Value(errorMessage),
      remoteFileId: remoteFileId == null && nullToAbsent
          ? const Value.absent()
          : Value(remoteFileId),
      contentHash: contentHash == null && nullToAbsent
          ? const Value.absent()
          : Value(contentHash),
      targetRelativePath: targetRelativePath == null && nullToAbsent
          ? const Value.absent()
          : Value(targetRelativePath),
    );
  }

  factory BackupQueueItemEntry.fromJson(Map<String, dynamic> json,
      {ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return BackupQueueItemEntry(
      localAssetId: serializer.fromJson<String>(json['localAssetId']),
      absolutePath: serializer.fromJson<String>(json['absolutePath']),
      displayName: serializer.fromJson<String>(json['displayName']),
      sizeBytes: serializer.fromJson<int>(json['sizeBytes']),
      modifiedAtMs: serializer.fromJson<int>(json['modifiedAtMs']),
      status: serializer.fromJson<String>(json['status']),
      progressBytes: serializer.fromJson<int>(json['progressBytes']),
      errorMessage: serializer.fromJson<String?>(json['errorMessage']),
      remoteFileId: serializer.fromJson<String?>(json['remoteFileId']),
      contentHash: serializer.fromJson<String?>(json['contentHash']),
      targetRelativePath:
          serializer.fromJson<String?>(json['targetRelativePath']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'localAssetId': serializer.toJson<String>(localAssetId),
      'absolutePath': serializer.toJson<String>(absolutePath),
      'displayName': serializer.toJson<String>(displayName),
      'sizeBytes': serializer.toJson<int>(sizeBytes),
      'modifiedAtMs': serializer.toJson<int>(modifiedAtMs),
      'status': serializer.toJson<String>(status),
      'progressBytes': serializer.toJson<int>(progressBytes),
      'errorMessage': serializer.toJson<String?>(errorMessage),
      'remoteFileId': serializer.toJson<String?>(remoteFileId),
      'contentHash': serializer.toJson<String?>(contentHash),
      'targetRelativePath': serializer.toJson<String?>(targetRelativePath),
    };
  }

  BackupQueueItemEntry copyWith(
          {String? localAssetId,
          String? absolutePath,
          String? displayName,
          int? sizeBytes,
          int? modifiedAtMs,
          String? status,
          int? progressBytes,
          Value<String?> errorMessage = const Value.absent(),
          Value<String?> remoteFileId = const Value.absent(),
          Value<String?> contentHash = const Value.absent(),
          Value<String?> targetRelativePath = const Value.absent()}) =>
      BackupQueueItemEntry(
        localAssetId: localAssetId ?? this.localAssetId,
        absolutePath: absolutePath ?? this.absolutePath,
        displayName: displayName ?? this.displayName,
        sizeBytes: sizeBytes ?? this.sizeBytes,
        modifiedAtMs: modifiedAtMs ?? this.modifiedAtMs,
        status: status ?? this.status,
        progressBytes: progressBytes ?? this.progressBytes,
        errorMessage:
            errorMessage.present ? errorMessage.value : this.errorMessage,
        remoteFileId:
            remoteFileId.present ? remoteFileId.value : this.remoteFileId,
        contentHash: contentHash.present ? contentHash.value : this.contentHash,
        targetRelativePath: targetRelativePath.present
            ? targetRelativePath.value
            : this.targetRelativePath,
      );
  BackupQueueItemEntry copyWithCompanion(BackupQueueItemsCompanion data) {
    return BackupQueueItemEntry(
      localAssetId: data.localAssetId.present
          ? data.localAssetId.value
          : this.localAssetId,
      absolutePath: data.absolutePath.present
          ? data.absolutePath.value
          : this.absolutePath,
      displayName:
          data.displayName.present ? data.displayName.value : this.displayName,
      sizeBytes: data.sizeBytes.present ? data.sizeBytes.value : this.sizeBytes,
      modifiedAtMs: data.modifiedAtMs.present
          ? data.modifiedAtMs.value
          : this.modifiedAtMs,
      status: data.status.present ? data.status.value : this.status,
      progressBytes: data.progressBytes.present
          ? data.progressBytes.value
          : this.progressBytes,
      errorMessage: data.errorMessage.present
          ? data.errorMessage.value
          : this.errorMessage,
      remoteFileId: data.remoteFileId.present
          ? data.remoteFileId.value
          : this.remoteFileId,
      contentHash:
          data.contentHash.present ? data.contentHash.value : this.contentHash,
      targetRelativePath: data.targetRelativePath.present
          ? data.targetRelativePath.value
          : this.targetRelativePath,
    );
  }

  @override
  String toString() {
    return (StringBuffer('BackupQueueItemEntry(')
          ..write('localAssetId: $localAssetId, ')
          ..write('absolutePath: $absolutePath, ')
          ..write('displayName: $displayName, ')
          ..write('sizeBytes: $sizeBytes, ')
          ..write('modifiedAtMs: $modifiedAtMs, ')
          ..write('status: $status, ')
          ..write('progressBytes: $progressBytes, ')
          ..write('errorMessage: $errorMessage, ')
          ..write('remoteFileId: $remoteFileId, ')
          ..write('contentHash: $contentHash, ')
          ..write('targetRelativePath: $targetRelativePath')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
      localAssetId,
      absolutePath,
      displayName,
      sizeBytes,
      modifiedAtMs,
      status,
      progressBytes,
      errorMessage,
      remoteFileId,
      contentHash,
      targetRelativePath);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is BackupQueueItemEntry &&
          other.localAssetId == this.localAssetId &&
          other.absolutePath == this.absolutePath &&
          other.displayName == this.displayName &&
          other.sizeBytes == this.sizeBytes &&
          other.modifiedAtMs == this.modifiedAtMs &&
          other.status == this.status &&
          other.progressBytes == this.progressBytes &&
          other.errorMessage == this.errorMessage &&
          other.remoteFileId == this.remoteFileId &&
          other.contentHash == this.contentHash &&
          other.targetRelativePath == this.targetRelativePath);
}

class BackupQueueItemsCompanion extends UpdateCompanion<BackupQueueItemEntry> {
  final Value<String> localAssetId;
  final Value<String> absolutePath;
  final Value<String> displayName;
  final Value<int> sizeBytes;
  final Value<int> modifiedAtMs;
  final Value<String> status;
  final Value<int> progressBytes;
  final Value<String?> errorMessage;
  final Value<String?> remoteFileId;
  final Value<String?> contentHash;
  final Value<String?> targetRelativePath;
  final Value<int> rowid;
  const BackupQueueItemsCompanion({
    this.localAssetId = const Value.absent(),
    this.absolutePath = const Value.absent(),
    this.displayName = const Value.absent(),
    this.sizeBytes = const Value.absent(),
    this.modifiedAtMs = const Value.absent(),
    this.status = const Value.absent(),
    this.progressBytes = const Value.absent(),
    this.errorMessage = const Value.absent(),
    this.remoteFileId = const Value.absent(),
    this.contentHash = const Value.absent(),
    this.targetRelativePath = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  BackupQueueItemsCompanion.insert({
    required String localAssetId,
    required String absolutePath,
    required String displayName,
    required int sizeBytes,
    required int modifiedAtMs,
    required String status,
    required int progressBytes,
    this.errorMessage = const Value.absent(),
    this.remoteFileId = const Value.absent(),
    this.contentHash = const Value.absent(),
    this.targetRelativePath = const Value.absent(),
    this.rowid = const Value.absent(),
  })  : localAssetId = Value(localAssetId),
        absolutePath = Value(absolutePath),
        displayName = Value(displayName),
        sizeBytes = Value(sizeBytes),
        modifiedAtMs = Value(modifiedAtMs),
        status = Value(status),
        progressBytes = Value(progressBytes);
  static Insertable<BackupQueueItemEntry> custom({
    Expression<String>? localAssetId,
    Expression<String>? absolutePath,
    Expression<String>? displayName,
    Expression<int>? sizeBytes,
    Expression<int>? modifiedAtMs,
    Expression<String>? status,
    Expression<int>? progressBytes,
    Expression<String>? errorMessage,
    Expression<String>? remoteFileId,
    Expression<String>? contentHash,
    Expression<String>? targetRelativePath,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (localAssetId != null) 'local_asset_id': localAssetId,
      if (absolutePath != null) 'absolute_path': absolutePath,
      if (displayName != null) 'display_name': displayName,
      if (sizeBytes != null) 'size_bytes': sizeBytes,
      if (modifiedAtMs != null) 'modified_at_ms': modifiedAtMs,
      if (status != null) 'status': status,
      if (progressBytes != null) 'progress_bytes': progressBytes,
      if (errorMessage != null) 'error_message': errorMessage,
      if (remoteFileId != null) 'remote_file_id': remoteFileId,
      if (contentHash != null) 'content_hash': contentHash,
      if (targetRelativePath != null)
        'target_relative_path': targetRelativePath,
      if (rowid != null) 'rowid': rowid,
    });
  }

  BackupQueueItemsCompanion copyWith(
      {Value<String>? localAssetId,
      Value<String>? absolutePath,
      Value<String>? displayName,
      Value<int>? sizeBytes,
      Value<int>? modifiedAtMs,
      Value<String>? status,
      Value<int>? progressBytes,
      Value<String?>? errorMessage,
      Value<String?>? remoteFileId,
      Value<String?>? contentHash,
      Value<String?>? targetRelativePath,
      Value<int>? rowid}) {
    return BackupQueueItemsCompanion(
      localAssetId: localAssetId ?? this.localAssetId,
      absolutePath: absolutePath ?? this.absolutePath,
      displayName: displayName ?? this.displayName,
      sizeBytes: sizeBytes ?? this.sizeBytes,
      modifiedAtMs: modifiedAtMs ?? this.modifiedAtMs,
      status: status ?? this.status,
      progressBytes: progressBytes ?? this.progressBytes,
      errorMessage: errorMessage ?? this.errorMessage,
      remoteFileId: remoteFileId ?? this.remoteFileId,
      contentHash: contentHash ?? this.contentHash,
      targetRelativePath: targetRelativePath ?? this.targetRelativePath,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (localAssetId.present) {
      map['local_asset_id'] = Variable<String>(localAssetId.value);
    }
    if (absolutePath.present) {
      map['absolute_path'] = Variable<String>(absolutePath.value);
    }
    if (displayName.present) {
      map['display_name'] = Variable<String>(displayName.value);
    }
    if (sizeBytes.present) {
      map['size_bytes'] = Variable<int>(sizeBytes.value);
    }
    if (modifiedAtMs.present) {
      map['modified_at_ms'] = Variable<int>(modifiedAtMs.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (progressBytes.present) {
      map['progress_bytes'] = Variable<int>(progressBytes.value);
    }
    if (errorMessage.present) {
      map['error_message'] = Variable<String>(errorMessage.value);
    }
    if (remoteFileId.present) {
      map['remote_file_id'] = Variable<String>(remoteFileId.value);
    }
    if (contentHash.present) {
      map['content_hash'] = Variable<String>(contentHash.value);
    }
    if (targetRelativePath.present) {
      map['target_relative_path'] = Variable<String>(targetRelativePath.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('BackupQueueItemsCompanion(')
          ..write('localAssetId: $localAssetId, ')
          ..write('absolutePath: $absolutePath, ')
          ..write('displayName: $displayName, ')
          ..write('sizeBytes: $sizeBytes, ')
          ..write('modifiedAtMs: $modifiedAtMs, ')
          ..write('status: $status, ')
          ..write('progressBytes: $progressBytes, ')
          ..write('errorMessage: $errorMessage, ')
          ..write('remoteFileId: $remoteFileId, ')
          ..write('contentHash: $contentHash, ')
          ..write('targetRelativePath: $targetRelativePath, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $BackupRunLogsTable extends BackupRunLogs
    with TableInfo<$BackupRunLogsTable, BackupRunLogEntry> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $BackupRunLogsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
      'id', aliasedName, false,
      hasAutoIncrement: true,
      type: DriftSqlType.int,
      requiredDuringInsert: false,
      defaultConstraints:
          GeneratedColumn.constraintIsAlways('PRIMARY KEY AUTOINCREMENT'));
  static const VerificationMeta _runIdMeta = const VerificationMeta('runId');
  @override
  late final GeneratedColumn<String> runId = GeneratedColumn<String>(
      'run_id', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _startedAtMeta =
      const VerificationMeta('startedAt');
  @override
  late final GeneratedColumn<DateTime> startedAt = GeneratedColumn<DateTime>(
      'started_at', aliasedName, false,
      type: DriftSqlType.dateTime, requiredDuringInsert: true);
  static const VerificationMeta _endedAtMeta =
      const VerificationMeta('endedAt');
  @override
  late final GeneratedColumn<DateTime> endedAt = GeneratedColumn<DateTime>(
      'ended_at', aliasedName, true,
      type: DriftSqlType.dateTime, requiredDuringInsert: false);
  static const VerificationMeta _modeMeta = const VerificationMeta('mode');
  @override
  late final GeneratedColumn<String> mode = GeneratedColumn<String>(
      'mode', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _doneCountMeta =
      const VerificationMeta('doneCount');
  @override
  late final GeneratedColumn<int> doneCount = GeneratedColumn<int>(
      'done_count', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _failedCountMeta =
      const VerificationMeta('failedCount');
  @override
  late final GeneratedColumn<int> failedCount = GeneratedColumn<int>(
      'failed_count', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _skippedCountMeta =
      const VerificationMeta('skippedCount');
  @override
  late final GeneratedColumn<int> skippedCount = GeneratedColumn<int>(
      'skipped_count', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _totalBytesMeta =
      const VerificationMeta('totalBytes');
  @override
  late final GeneratedColumn<int> totalBytes = GeneratedColumn<int>(
      'total_bytes', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  @override
  List<GeneratedColumn> get $columns => [
        id,
        runId,
        startedAt,
        endedAt,
        mode,
        doneCount,
        failedCount,
        skippedCount,
        totalBytes
      ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'backup_run_logs';
  @override
  VerificationContext validateIntegrity(Insertable<BackupRunLogEntry> instance,
      {bool isInserting = false}) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('run_id')) {
      context.handle(
          _runIdMeta, runId.isAcceptableOrUnknown(data['run_id']!, _runIdMeta));
    } else if (isInserting) {
      context.missing(_runIdMeta);
    }
    if (data.containsKey('started_at')) {
      context.handle(_startedAtMeta,
          startedAt.isAcceptableOrUnknown(data['started_at']!, _startedAtMeta));
    } else if (isInserting) {
      context.missing(_startedAtMeta);
    }
    if (data.containsKey('ended_at')) {
      context.handle(_endedAtMeta,
          endedAt.isAcceptableOrUnknown(data['ended_at']!, _endedAtMeta));
    }
    if (data.containsKey('mode')) {
      context.handle(
          _modeMeta, mode.isAcceptableOrUnknown(data['mode']!, _modeMeta));
    } else if (isInserting) {
      context.missing(_modeMeta);
    }
    if (data.containsKey('done_count')) {
      context.handle(_doneCountMeta,
          doneCount.isAcceptableOrUnknown(data['done_count']!, _doneCountMeta));
    } else if (isInserting) {
      context.missing(_doneCountMeta);
    }
    if (data.containsKey('failed_count')) {
      context.handle(
          _failedCountMeta,
          failedCount.isAcceptableOrUnknown(
              data['failed_count']!, _failedCountMeta));
    } else if (isInserting) {
      context.missing(_failedCountMeta);
    }
    if (data.containsKey('skipped_count')) {
      context.handle(
          _skippedCountMeta,
          skippedCount.isAcceptableOrUnknown(
              data['skipped_count']!, _skippedCountMeta));
    } else if (isInserting) {
      context.missing(_skippedCountMeta);
    }
    if (data.containsKey('total_bytes')) {
      context.handle(
          _totalBytesMeta,
          totalBytes.isAcceptableOrUnknown(
              data['total_bytes']!, _totalBytesMeta));
    } else if (isInserting) {
      context.missing(_totalBytesMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  BackupRunLogEntry map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return BackupRunLogEntry(
      id: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}id'])!,
      runId: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}run_id'])!,
      startedAt: attachedDatabase.typeMapping
          .read(DriftSqlType.dateTime, data['${effectivePrefix}started_at'])!,
      endedAt: attachedDatabase.typeMapping
          .read(DriftSqlType.dateTime, data['${effectivePrefix}ended_at']),
      mode: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}mode'])!,
      doneCount: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}done_count'])!,
      failedCount: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}failed_count'])!,
      skippedCount: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}skipped_count'])!,
      totalBytes: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}total_bytes'])!,
    );
  }

  @override
  $BackupRunLogsTable createAlias(String alias) {
    return $BackupRunLogsTable(attachedDatabase, alias);
  }
}

class BackupRunLogEntry extends DataClass
    implements Insertable<BackupRunLogEntry> {
  final int id;
  final String runId;
  final DateTime startedAt;
  final DateTime? endedAt;
  final String mode;
  final int doneCount;
  final int failedCount;
  final int skippedCount;
  final int totalBytes;
  const BackupRunLogEntry(
      {required this.id,
      required this.runId,
      required this.startedAt,
      this.endedAt,
      required this.mode,
      required this.doneCount,
      required this.failedCount,
      required this.skippedCount,
      required this.totalBytes});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['run_id'] = Variable<String>(runId);
    map['started_at'] = Variable<DateTime>(startedAt);
    if (!nullToAbsent || endedAt != null) {
      map['ended_at'] = Variable<DateTime>(endedAt);
    }
    map['mode'] = Variable<String>(mode);
    map['done_count'] = Variable<int>(doneCount);
    map['failed_count'] = Variable<int>(failedCount);
    map['skipped_count'] = Variable<int>(skippedCount);
    map['total_bytes'] = Variable<int>(totalBytes);
    return map;
  }

  BackupRunLogsCompanion toCompanion(bool nullToAbsent) {
    return BackupRunLogsCompanion(
      id: Value(id),
      runId: Value(runId),
      startedAt: Value(startedAt),
      endedAt: endedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(endedAt),
      mode: Value(mode),
      doneCount: Value(doneCount),
      failedCount: Value(failedCount),
      skippedCount: Value(skippedCount),
      totalBytes: Value(totalBytes),
    );
  }

  factory BackupRunLogEntry.fromJson(Map<String, dynamic> json,
      {ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return BackupRunLogEntry(
      id: serializer.fromJson<int>(json['id']),
      runId: serializer.fromJson<String>(json['runId']),
      startedAt: serializer.fromJson<DateTime>(json['startedAt']),
      endedAt: serializer.fromJson<DateTime?>(json['endedAt']),
      mode: serializer.fromJson<String>(json['mode']),
      doneCount: serializer.fromJson<int>(json['doneCount']),
      failedCount: serializer.fromJson<int>(json['failedCount']),
      skippedCount: serializer.fromJson<int>(json['skippedCount']),
      totalBytes: serializer.fromJson<int>(json['totalBytes']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'runId': serializer.toJson<String>(runId),
      'startedAt': serializer.toJson<DateTime>(startedAt),
      'endedAt': serializer.toJson<DateTime?>(endedAt),
      'mode': serializer.toJson<String>(mode),
      'doneCount': serializer.toJson<int>(doneCount),
      'failedCount': serializer.toJson<int>(failedCount),
      'skippedCount': serializer.toJson<int>(skippedCount),
      'totalBytes': serializer.toJson<int>(totalBytes),
    };
  }

  BackupRunLogEntry copyWith(
          {int? id,
          String? runId,
          DateTime? startedAt,
          Value<DateTime?> endedAt = const Value.absent(),
          String? mode,
          int? doneCount,
          int? failedCount,
          int? skippedCount,
          int? totalBytes}) =>
      BackupRunLogEntry(
        id: id ?? this.id,
        runId: runId ?? this.runId,
        startedAt: startedAt ?? this.startedAt,
        endedAt: endedAt.present ? endedAt.value : this.endedAt,
        mode: mode ?? this.mode,
        doneCount: doneCount ?? this.doneCount,
        failedCount: failedCount ?? this.failedCount,
        skippedCount: skippedCount ?? this.skippedCount,
        totalBytes: totalBytes ?? this.totalBytes,
      );
  BackupRunLogEntry copyWithCompanion(BackupRunLogsCompanion data) {
    return BackupRunLogEntry(
      id: data.id.present ? data.id.value : this.id,
      runId: data.runId.present ? data.runId.value : this.runId,
      startedAt: data.startedAt.present ? data.startedAt.value : this.startedAt,
      endedAt: data.endedAt.present ? data.endedAt.value : this.endedAt,
      mode: data.mode.present ? data.mode.value : this.mode,
      doneCount: data.doneCount.present ? data.doneCount.value : this.doneCount,
      failedCount:
          data.failedCount.present ? data.failedCount.value : this.failedCount,
      skippedCount: data.skippedCount.present
          ? data.skippedCount.value
          : this.skippedCount,
      totalBytes:
          data.totalBytes.present ? data.totalBytes.value : this.totalBytes,
    );
  }

  @override
  String toString() {
    return (StringBuffer('BackupRunLogEntry(')
          ..write('id: $id, ')
          ..write('runId: $runId, ')
          ..write('startedAt: $startedAt, ')
          ..write('endedAt: $endedAt, ')
          ..write('mode: $mode, ')
          ..write('doneCount: $doneCount, ')
          ..write('failedCount: $failedCount, ')
          ..write('skippedCount: $skippedCount, ')
          ..write('totalBytes: $totalBytes')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, runId, startedAt, endedAt, mode,
      doneCount, failedCount, skippedCount, totalBytes);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is BackupRunLogEntry &&
          other.id == this.id &&
          other.runId == this.runId &&
          other.startedAt == this.startedAt &&
          other.endedAt == this.endedAt &&
          other.mode == this.mode &&
          other.doneCount == this.doneCount &&
          other.failedCount == this.failedCount &&
          other.skippedCount == this.skippedCount &&
          other.totalBytes == this.totalBytes);
}

class BackupRunLogsCompanion extends UpdateCompanion<BackupRunLogEntry> {
  final Value<int> id;
  final Value<String> runId;
  final Value<DateTime> startedAt;
  final Value<DateTime?> endedAt;
  final Value<String> mode;
  final Value<int> doneCount;
  final Value<int> failedCount;
  final Value<int> skippedCount;
  final Value<int> totalBytes;
  const BackupRunLogsCompanion({
    this.id = const Value.absent(),
    this.runId = const Value.absent(),
    this.startedAt = const Value.absent(),
    this.endedAt = const Value.absent(),
    this.mode = const Value.absent(),
    this.doneCount = const Value.absent(),
    this.failedCount = const Value.absent(),
    this.skippedCount = const Value.absent(),
    this.totalBytes = const Value.absent(),
  });
  BackupRunLogsCompanion.insert({
    this.id = const Value.absent(),
    required String runId,
    required DateTime startedAt,
    this.endedAt = const Value.absent(),
    required String mode,
    required int doneCount,
    required int failedCount,
    required int skippedCount,
    required int totalBytes,
  })  : runId = Value(runId),
        startedAt = Value(startedAt),
        mode = Value(mode),
        doneCount = Value(doneCount),
        failedCount = Value(failedCount),
        skippedCount = Value(skippedCount),
        totalBytes = Value(totalBytes);
  static Insertable<BackupRunLogEntry> custom({
    Expression<int>? id,
    Expression<String>? runId,
    Expression<DateTime>? startedAt,
    Expression<DateTime>? endedAt,
    Expression<String>? mode,
    Expression<int>? doneCount,
    Expression<int>? failedCount,
    Expression<int>? skippedCount,
    Expression<int>? totalBytes,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (runId != null) 'run_id': runId,
      if (startedAt != null) 'started_at': startedAt,
      if (endedAt != null) 'ended_at': endedAt,
      if (mode != null) 'mode': mode,
      if (doneCount != null) 'done_count': doneCount,
      if (failedCount != null) 'failed_count': failedCount,
      if (skippedCount != null) 'skipped_count': skippedCount,
      if (totalBytes != null) 'total_bytes': totalBytes,
    });
  }

  BackupRunLogsCompanion copyWith(
      {Value<int>? id,
      Value<String>? runId,
      Value<DateTime>? startedAt,
      Value<DateTime?>? endedAt,
      Value<String>? mode,
      Value<int>? doneCount,
      Value<int>? failedCount,
      Value<int>? skippedCount,
      Value<int>? totalBytes}) {
    return BackupRunLogsCompanion(
      id: id ?? this.id,
      runId: runId ?? this.runId,
      startedAt: startedAt ?? this.startedAt,
      endedAt: endedAt ?? this.endedAt,
      mode: mode ?? this.mode,
      doneCount: doneCount ?? this.doneCount,
      failedCount: failedCount ?? this.failedCount,
      skippedCount: skippedCount ?? this.skippedCount,
      totalBytes: totalBytes ?? this.totalBytes,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (runId.present) {
      map['run_id'] = Variable<String>(runId.value);
    }
    if (startedAt.present) {
      map['started_at'] = Variable<DateTime>(startedAt.value);
    }
    if (endedAt.present) {
      map['ended_at'] = Variable<DateTime>(endedAt.value);
    }
    if (mode.present) {
      map['mode'] = Variable<String>(mode.value);
    }
    if (doneCount.present) {
      map['done_count'] = Variable<int>(doneCount.value);
    }
    if (failedCount.present) {
      map['failed_count'] = Variable<int>(failedCount.value);
    }
    if (skippedCount.present) {
      map['skipped_count'] = Variable<int>(skippedCount.value);
    }
    if (totalBytes.present) {
      map['total_bytes'] = Variable<int>(totalBytes.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('BackupRunLogsCompanion(')
          ..write('id: $id, ')
          ..write('runId: $runId, ')
          ..write('startedAt: $startedAt, ')
          ..write('endedAt: $endedAt, ')
          ..write('mode: $mode, ')
          ..write('doneCount: $doneCount, ')
          ..write('failedCount: $failedCount, ')
          ..write('skippedCount: $skippedCount, ')
          ..write('totalBytes: $totalBytes')
          ..write(')'))
        .toString();
  }
}

abstract class _$BackupDatabase extends GeneratedDatabase {
  _$BackupDatabase(QueryExecutor e) : super(e);
  $BackupDatabaseManager get managers => $BackupDatabaseManager(this);
  late final $BackupQueueItemsTable backupQueueItems =
      $BackupQueueItemsTable(this);
  late final $BackupRunLogsTable backupRunLogs = $BackupRunLogsTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities =>
      [backupQueueItems, backupRunLogs];
}

typedef $$BackupQueueItemsTableCreateCompanionBuilder
    = BackupQueueItemsCompanion Function({
  required String localAssetId,
  required String absolutePath,
  required String displayName,
  required int sizeBytes,
  required int modifiedAtMs,
  required String status,
  required int progressBytes,
  Value<String?> errorMessage,
  Value<String?> remoteFileId,
  Value<String?> contentHash,
  Value<String?> targetRelativePath,
  Value<int> rowid,
});
typedef $$BackupQueueItemsTableUpdateCompanionBuilder
    = BackupQueueItemsCompanion Function({
  Value<String> localAssetId,
  Value<String> absolutePath,
  Value<String> displayName,
  Value<int> sizeBytes,
  Value<int> modifiedAtMs,
  Value<String> status,
  Value<int> progressBytes,
  Value<String?> errorMessage,
  Value<String?> remoteFileId,
  Value<String?> contentHash,
  Value<String?> targetRelativePath,
  Value<int> rowid,
});

class $$BackupQueueItemsTableFilterComposer
    extends Composer<_$BackupDatabase, $BackupQueueItemsTable> {
  $$BackupQueueItemsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get localAssetId => $composableBuilder(
      column: $table.localAssetId, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get absolutePath => $composableBuilder(
      column: $table.absolutePath, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get displayName => $composableBuilder(
      column: $table.displayName, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get sizeBytes => $composableBuilder(
      column: $table.sizeBytes, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get modifiedAtMs => $composableBuilder(
      column: $table.modifiedAtMs, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get status => $composableBuilder(
      column: $table.status, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get progressBytes => $composableBuilder(
      column: $table.progressBytes, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get errorMessage => $composableBuilder(
      column: $table.errorMessage, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get remoteFileId => $composableBuilder(
      column: $table.remoteFileId, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get contentHash => $composableBuilder(
      column: $table.contentHash, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get targetRelativePath => $composableBuilder(
      column: $table.targetRelativePath,
      builder: (column) => ColumnFilters(column));
}

class $$BackupQueueItemsTableOrderingComposer
    extends Composer<_$BackupDatabase, $BackupQueueItemsTable> {
  $$BackupQueueItemsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get localAssetId => $composableBuilder(
      column: $table.localAssetId,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get absolutePath => $composableBuilder(
      column: $table.absolutePath,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get displayName => $composableBuilder(
      column: $table.displayName, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get sizeBytes => $composableBuilder(
      column: $table.sizeBytes, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get modifiedAtMs => $composableBuilder(
      column: $table.modifiedAtMs,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get status => $composableBuilder(
      column: $table.status, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get progressBytes => $composableBuilder(
      column: $table.progressBytes,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get errorMessage => $composableBuilder(
      column: $table.errorMessage,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get remoteFileId => $composableBuilder(
      column: $table.remoteFileId,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get contentHash => $composableBuilder(
      column: $table.contentHash, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get targetRelativePath => $composableBuilder(
      column: $table.targetRelativePath,
      builder: (column) => ColumnOrderings(column));
}

class $$BackupQueueItemsTableAnnotationComposer
    extends Composer<_$BackupDatabase, $BackupQueueItemsTable> {
  $$BackupQueueItemsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get localAssetId => $composableBuilder(
      column: $table.localAssetId, builder: (column) => column);

  GeneratedColumn<String> get absolutePath => $composableBuilder(
      column: $table.absolutePath, builder: (column) => column);

  GeneratedColumn<String> get displayName => $composableBuilder(
      column: $table.displayName, builder: (column) => column);

  GeneratedColumn<int> get sizeBytes =>
      $composableBuilder(column: $table.sizeBytes, builder: (column) => column);

  GeneratedColumn<int> get modifiedAtMs => $composableBuilder(
      column: $table.modifiedAtMs, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<int> get progressBytes => $composableBuilder(
      column: $table.progressBytes, builder: (column) => column);

  GeneratedColumn<String> get errorMessage => $composableBuilder(
      column: $table.errorMessage, builder: (column) => column);

  GeneratedColumn<String> get remoteFileId => $composableBuilder(
      column: $table.remoteFileId, builder: (column) => column);

  GeneratedColumn<String> get contentHash => $composableBuilder(
      column: $table.contentHash, builder: (column) => column);

  GeneratedColumn<String> get targetRelativePath => $composableBuilder(
      column: $table.targetRelativePath, builder: (column) => column);
}

class $$BackupQueueItemsTableTableManager extends RootTableManager<
    _$BackupDatabase,
    $BackupQueueItemsTable,
    BackupQueueItemEntry,
    $$BackupQueueItemsTableFilterComposer,
    $$BackupQueueItemsTableOrderingComposer,
    $$BackupQueueItemsTableAnnotationComposer,
    $$BackupQueueItemsTableCreateCompanionBuilder,
    $$BackupQueueItemsTableUpdateCompanionBuilder,
    (
      BackupQueueItemEntry,
      BaseReferences<_$BackupDatabase, $BackupQueueItemsTable,
          BackupQueueItemEntry>
    ),
    BackupQueueItemEntry,
    PrefetchHooks Function()> {
  $$BackupQueueItemsTableTableManager(
      _$BackupDatabase db, $BackupQueueItemsTable table)
      : super(TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$BackupQueueItemsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$BackupQueueItemsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$BackupQueueItemsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback: ({
            Value<String> localAssetId = const Value.absent(),
            Value<String> absolutePath = const Value.absent(),
            Value<String> displayName = const Value.absent(),
            Value<int> sizeBytes = const Value.absent(),
            Value<int> modifiedAtMs = const Value.absent(),
            Value<String> status = const Value.absent(),
            Value<int> progressBytes = const Value.absent(),
            Value<String?> errorMessage = const Value.absent(),
            Value<String?> remoteFileId = const Value.absent(),
            Value<String?> contentHash = const Value.absent(),
            Value<String?> targetRelativePath = const Value.absent(),
            Value<int> rowid = const Value.absent(),
          }) =>
              BackupQueueItemsCompanion(
            localAssetId: localAssetId,
            absolutePath: absolutePath,
            displayName: displayName,
            sizeBytes: sizeBytes,
            modifiedAtMs: modifiedAtMs,
            status: status,
            progressBytes: progressBytes,
            errorMessage: errorMessage,
            remoteFileId: remoteFileId,
            contentHash: contentHash,
            targetRelativePath: targetRelativePath,
            rowid: rowid,
          ),
          createCompanionCallback: ({
            required String localAssetId,
            required String absolutePath,
            required String displayName,
            required int sizeBytes,
            required int modifiedAtMs,
            required String status,
            required int progressBytes,
            Value<String?> errorMessage = const Value.absent(),
            Value<String?> remoteFileId = const Value.absent(),
            Value<String?> contentHash = const Value.absent(),
            Value<String?> targetRelativePath = const Value.absent(),
            Value<int> rowid = const Value.absent(),
          }) =>
              BackupQueueItemsCompanion.insert(
            localAssetId: localAssetId,
            absolutePath: absolutePath,
            displayName: displayName,
            sizeBytes: sizeBytes,
            modifiedAtMs: modifiedAtMs,
            status: status,
            progressBytes: progressBytes,
            errorMessage: errorMessage,
            remoteFileId: remoteFileId,
            contentHash: contentHash,
            targetRelativePath: targetRelativePath,
            rowid: rowid,
          ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ));
}

typedef $$BackupQueueItemsTableProcessedTableManager = ProcessedTableManager<
    _$BackupDatabase,
    $BackupQueueItemsTable,
    BackupQueueItemEntry,
    $$BackupQueueItemsTableFilterComposer,
    $$BackupQueueItemsTableOrderingComposer,
    $$BackupQueueItemsTableAnnotationComposer,
    $$BackupQueueItemsTableCreateCompanionBuilder,
    $$BackupQueueItemsTableUpdateCompanionBuilder,
    (
      BackupQueueItemEntry,
      BaseReferences<_$BackupDatabase, $BackupQueueItemsTable,
          BackupQueueItemEntry>
    ),
    BackupQueueItemEntry,
    PrefetchHooks Function()>;
typedef $$BackupRunLogsTableCreateCompanionBuilder = BackupRunLogsCompanion
    Function({
  Value<int> id,
  required String runId,
  required DateTime startedAt,
  Value<DateTime?> endedAt,
  required String mode,
  required int doneCount,
  required int failedCount,
  required int skippedCount,
  required int totalBytes,
});
typedef $$BackupRunLogsTableUpdateCompanionBuilder = BackupRunLogsCompanion
    Function({
  Value<int> id,
  Value<String> runId,
  Value<DateTime> startedAt,
  Value<DateTime?> endedAt,
  Value<String> mode,
  Value<int> doneCount,
  Value<int> failedCount,
  Value<int> skippedCount,
  Value<int> totalBytes,
});

class $$BackupRunLogsTableFilterComposer
    extends Composer<_$BackupDatabase, $BackupRunLogsTable> {
  $$BackupRunLogsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
      column: $table.id, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get runId => $composableBuilder(
      column: $table.runId, builder: (column) => ColumnFilters(column));

  ColumnFilters<DateTime> get startedAt => $composableBuilder(
      column: $table.startedAt, builder: (column) => ColumnFilters(column));

  ColumnFilters<DateTime> get endedAt => $composableBuilder(
      column: $table.endedAt, builder: (column) => ColumnFilters(column));

  ColumnFilters<String> get mode => $composableBuilder(
      column: $table.mode, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get doneCount => $composableBuilder(
      column: $table.doneCount, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get failedCount => $composableBuilder(
      column: $table.failedCount, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get skippedCount => $composableBuilder(
      column: $table.skippedCount, builder: (column) => ColumnFilters(column));

  ColumnFilters<int> get totalBytes => $composableBuilder(
      column: $table.totalBytes, builder: (column) => ColumnFilters(column));
}

class $$BackupRunLogsTableOrderingComposer
    extends Composer<_$BackupDatabase, $BackupRunLogsTable> {
  $$BackupRunLogsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
      column: $table.id, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get runId => $composableBuilder(
      column: $table.runId, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<DateTime> get startedAt => $composableBuilder(
      column: $table.startedAt, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<DateTime> get endedAt => $composableBuilder(
      column: $table.endedAt, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<String> get mode => $composableBuilder(
      column: $table.mode, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get doneCount => $composableBuilder(
      column: $table.doneCount, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get failedCount => $composableBuilder(
      column: $table.failedCount, builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get skippedCount => $composableBuilder(
      column: $table.skippedCount,
      builder: (column) => ColumnOrderings(column));

  ColumnOrderings<int> get totalBytes => $composableBuilder(
      column: $table.totalBytes, builder: (column) => ColumnOrderings(column));
}

class $$BackupRunLogsTableAnnotationComposer
    extends Composer<_$BackupDatabase, $BackupRunLogsTable> {
  $$BackupRunLogsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get runId =>
      $composableBuilder(column: $table.runId, builder: (column) => column);

  GeneratedColumn<DateTime> get startedAt =>
      $composableBuilder(column: $table.startedAt, builder: (column) => column);

  GeneratedColumn<DateTime> get endedAt =>
      $composableBuilder(column: $table.endedAt, builder: (column) => column);

  GeneratedColumn<String> get mode =>
      $composableBuilder(column: $table.mode, builder: (column) => column);

  GeneratedColumn<int> get doneCount =>
      $composableBuilder(column: $table.doneCount, builder: (column) => column);

  GeneratedColumn<int> get failedCount => $composableBuilder(
      column: $table.failedCount, builder: (column) => column);

  GeneratedColumn<int> get skippedCount => $composableBuilder(
      column: $table.skippedCount, builder: (column) => column);

  GeneratedColumn<int> get totalBytes => $composableBuilder(
      column: $table.totalBytes, builder: (column) => column);
}

class $$BackupRunLogsTableTableManager extends RootTableManager<
    _$BackupDatabase,
    $BackupRunLogsTable,
    BackupRunLogEntry,
    $$BackupRunLogsTableFilterComposer,
    $$BackupRunLogsTableOrderingComposer,
    $$BackupRunLogsTableAnnotationComposer,
    $$BackupRunLogsTableCreateCompanionBuilder,
    $$BackupRunLogsTableUpdateCompanionBuilder,
    (
      BackupRunLogEntry,
      BaseReferences<_$BackupDatabase, $BackupRunLogsTable, BackupRunLogEntry>
    ),
    BackupRunLogEntry,
    PrefetchHooks Function()> {
  $$BackupRunLogsTableTableManager(
      _$BackupDatabase db, $BackupRunLogsTable table)
      : super(TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$BackupRunLogsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$BackupRunLogsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$BackupRunLogsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback: ({
            Value<int> id = const Value.absent(),
            Value<String> runId = const Value.absent(),
            Value<DateTime> startedAt = const Value.absent(),
            Value<DateTime?> endedAt = const Value.absent(),
            Value<String> mode = const Value.absent(),
            Value<int> doneCount = const Value.absent(),
            Value<int> failedCount = const Value.absent(),
            Value<int> skippedCount = const Value.absent(),
            Value<int> totalBytes = const Value.absent(),
          }) =>
              BackupRunLogsCompanion(
            id: id,
            runId: runId,
            startedAt: startedAt,
            endedAt: endedAt,
            mode: mode,
            doneCount: doneCount,
            failedCount: failedCount,
            skippedCount: skippedCount,
            totalBytes: totalBytes,
          ),
          createCompanionCallback: ({
            Value<int> id = const Value.absent(),
            required String runId,
            required DateTime startedAt,
            Value<DateTime?> endedAt = const Value.absent(),
            required String mode,
            required int doneCount,
            required int failedCount,
            required int skippedCount,
            required int totalBytes,
          }) =>
              BackupRunLogsCompanion.insert(
            id: id,
            runId: runId,
            startedAt: startedAt,
            endedAt: endedAt,
            mode: mode,
            doneCount: doneCount,
            failedCount: failedCount,
            skippedCount: skippedCount,
            totalBytes: totalBytes,
          ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ));
}

typedef $$BackupRunLogsTableProcessedTableManager = ProcessedTableManager<
    _$BackupDatabase,
    $BackupRunLogsTable,
    BackupRunLogEntry,
    $$BackupRunLogsTableFilterComposer,
    $$BackupRunLogsTableOrderingComposer,
    $$BackupRunLogsTableAnnotationComposer,
    $$BackupRunLogsTableCreateCompanionBuilder,
    $$BackupRunLogsTableUpdateCompanionBuilder,
    (
      BackupRunLogEntry,
      BaseReferences<_$BackupDatabase, $BackupRunLogsTable, BackupRunLogEntry>
    ),
    BackupRunLogEntry,
    PrefetchHooks Function()>;

class $BackupDatabaseManager {
  final _$BackupDatabase _db;
  $BackupDatabaseManager(this._db);
  $$BackupQueueItemsTableTableManager get backupQueueItems =>
      $$BackupQueueItemsTableTableManager(_db, _db.backupQueueItems);
  $$BackupRunLogsTableTableManager get backupRunLogs =>
      $$BackupRunLogsTableTableManager(_db, _db.backupRunLogs);
}
