# QRS (Query Result Store) -- 要件仕様（実装前）

## 1. 目的

QRS（Query Result Store）は、
Redash で定義されたクエリを定期実行し、
後から Redash 自体などの BI ツールで分析できるように結果を保存するシステムです。

QRS が重視する点: - 信頼性の高いスケジュール実行 - クエリ結果のデータセット保存 - Redash 結果の時系列蓄積

QRS は可視化機能や BI 機能を**提供しません**。

------------------------------------------------------------------------

# 2. スコープ

QRS の責務:

-   Redash クエリを API 経由で実行する
-   クエリ結果をデータベースに保存する
-   実行履歴を管理する
-   snapshot / latest 形式の結果保存をサポートする
-   決定的なスケジューリングとリトライ動作を提供する

スコープ外:

-   可視化やダッシュボード
-   SQL 編集またはクエリ作成
-   クエリパラメータ検証ロジック
-   データ品質検証
-   スキーママイグレーションの自動化

------------------------------------------------------------------------

# 3. Redash 連携

## 3.1 Redash インスタンス

QRS は複数の Redash インスタンスをサポートします。

各インスタンスは以下を持ちます:

-   `instance_id`
-   `base_url`
-   `api_key`（ユーザー / サービスアカウント）
-   有効 / 無効フラグ

### 接続テスト

接続テストは即時実行できます（GUI トリガー）。

目的: - API 接続性の検証 - 認証の検証 - インスタンス可用性の確認

------------------------------------------------------------------------

# 4. Dataset

**Dataset** は 1 つの Redash クエリを表します。

Dataset は以下を含みます:

-   `dataset_id`
-   `instance_id`
-   `query_id`

Dataset の作成方法:

1.  Redash Query URL
2.  インスタンス選択 + Query ID

URL 例:

    http://redash.example.com/queries/123

QRS は `query_id` を自動抽出します。

------------------------------------------------------------------------

# 5. Variant

**Variant** は Dataset の実行方法と保存方法を定義します。

特性:

-   不変の `variant_id`
-   パラメータマッピング
-   列型 override 設定（任意）
-   保存モード
-   スケジューリング設定

Variant は有効 / 無効を切り替えできます。

列型 override の運用ルール:

-   override は **Variant 新規作成時のみ** 設定可能
-   Variant 編集画面では override は表示のみ（変更不可）
-   override を変更したい場合は、既存 Variant をコピーして新規作成する
-   override は初回成功時の保存テーブル作成時に適用し、以後はスキーマ固定として扱う

------------------------------------------------------------------------

# 6. 実行モード

Variant は以下のモードをサポートします:

### snapshot

タイムバケットを使った時系列保存。

### latest

最新バージョンのデータセットのみを保存。

### oneshot

自動更新なしの単発実行。

------------------------------------------------------------------------

# 7. バケット戦略（snapshot モード）

Snapshot Variant では以下を定義します:

必須:

-   `interval`
-   `lag`
-   `lookback`

任意:

-   `start_at`

### Catch-up 動作

実行が遅延した場合、QRS は現在時刻まで不足バケットを実行して**必ず追いつく必要があります**。

------------------------------------------------------------------------

# 8. パラメータ割り当て

パラメータは Variant ごとに割り当てます。

サポートされる値ソース:

-   固定値（Fixed value）
-   BucketAt
-   Now
-   将来拡張可能な式

### デフォルト値

Dataset 作成時、クエリ URL のパラメータはデフォルトの **Fixed** 値として使われます。

例:

    ...?country=JP

→ `country = Fixed("JP")`

------------------------------------------------------------------------

# 9. 実行モデル

GUI 操作はジョブを直接実行しません。

代わりに、バックグラウンドディスパッチャが処理する **バケット状態レコード**（`qrs_sys_buckets`）を作成・更新します。

ディスパッチャは定期実行されます（例: 毎分）。

### 初期実行動作

-   concurrency = 1
-   逐次実行

将来的には並行数の設定可能化をサポートする場合があります。

## 9.1 Worker 実行制御（運用設定）

Worker は `qrs_sys_meta` の運用設定を参照して挙動を制御する。

主要設定:

-   `worker.global_concurrency`
    -   同時実行数（将来の並列実行時に使用）
    -   推奨初期値: `1`
-   `worker.max_run_seconds`
    -   1回の Worker 起動で新規ジョブを取得し続ける上限秒数
    -   推奨初期値: `150`（毎分 cron + flock 運用を想定）
-   `worker.max_jobs_per_run`
    -   1回の Worker 起動で処理する最大件数
    -   推奨初期値: `20`
-   `worker.poll_timeout_seconds`
    -   Redash ジョブ完了待ちのタイムアウト秒数
    -   推奨初期値: `300`
-   `worker.poll_interval_millis`
    -   Redash ジョブ状態ポーリング間隔（ミリ秒）
    -   推奨初期値: `1000`
-   `worker.running_stale_seconds`
    -   `running` 状態のまま停止したタスクを `queued_retry` に戻すまでの秒数
    -   推奨初期値: `900`
-   `worker.retry_max_count`
    -   自動リトライの上限回数（初回失敗後に再投入できる最大回数）
    -   推奨初期値: `3`
-   `worker.retry_backoff_seconds`
    -   自動リトライの基本待機秒数（指数バックオフの基準値）
    -   推奨初期値: `60`

終了条件:

-   `max_run_seconds` 到達後は**新規ジョブを取得しない**
-   すでに `running` に遷移済みのジョブは完了まで待機してから終了する

`running` 復旧:

-   Worker 起動時に `running` かつ `locked_at` が `running_stale_seconds` を超過したレコードを検出し、`queued_retry` に戻す
-   `locked_by`, `locked_at`, `started_at` はクリアし、`last_error` に自動復旧理由を記録する
-   復旧後も `running` レコードが残る場合、Worker は異常終了し、dispatch/execute を開始しない

デフォルト値の考え方:

-   `poll_timeout_seconds=300` に対して `running_stale_seconds=900` は 3 倍で、誤回収を避ける安全側設定
-   復旧を早めたい運用では `600`（2 倍）程度も選択可能

並列実行時の制約:

-   同一 `variant_id` は同時実行しない（`per_variant_concurrency=1`）
-   同一 `dataset_id`（同一保存先テーブル）は同時実行しない（`per_dataset_concurrency=1`）

注記:

-   `worker.dispatch_target_limit_per_variant` は現時点では採用しない（実行定義UIで lookback 対象を確認できるため）

------------------------------------------------------------------------

# 10. 実行優先度

Worker は以下の順でタスクを処理します:

1.  手動単発実行
2.  スケジュール実行
3.  Backfill 実行
4.  失敗実行のリトライ（常に最後）

優先度は固定値（数値が大きいほど高優先）で管理します:

-   `queued_scheduled`: `400`
-   `queued_manual`: `200`
-   `queued_backfill`（lookback を含む）: `100`
-   `queued_retry`: `50`（固定で最後に回す）

同一優先度内の実行順:

-   `execute_at` / `execute_after` の昇順（古い時刻から先に実行）
-   同一優先度かつ同時刻で複数候補がある場合の最終決定は Worker 実装仕様に委ねる

注記:

-   実装上は `status` のカテゴリ順（`manual/scheduled/backfill/retry`）を優先し、その後に `priority` を比較する
-   そのため、`queued_retry` は元の `priority` 値が高くても、非リトライタスクより先に実行しない

------------------------------------------------------------------------

# 11. リトライポリシー

失敗実行は:

-   自動でリトライされる
-   設定可能なリトライ上限を持つ
-   上限到達後はリトライを停止する
-   リトライ時は `execute_after` に待機時間を設定する

リトライ待機は指数バックオフ:

-   `retry_backoff_seconds * 2^(retry_attempt - 1)` を次回待機秒数として使用
-   例: `60` 秒設定なら `60s, 120s, 240s ...`

ユーザーは手動で再実行をトリガーできます。

------------------------------------------------------------------------

# 11.1 タイムゾーンと時刻管理

QRS は内部時刻を **UTC ではなくシステム設定タイムゾーンのローカル時刻** で統一します。

-   セットアップ時に `timezone_id`（例: `Asia/Tokyo`）を設定する
-   `timezone_id` は運用開始後は変更不可（メンテナンス手順を除く）
-   スケジュール計算（`bucket_at`, `execute_at`, `execute_after`）は `timezone_id` 基準で行う
-   監査/メタデータ時刻（`created_at`, `updated_at`, `start_time`, `end_time`, `qrs_ingested_at`, `qrs_bucket_at`）も `timezone_id` 基準で保存する

注記:

-   タイムゾーン変更はバケット境界や重複判定に影響するため、通常運用では禁止
-   DST のあるタイムゾーンを使う場合は、境界時刻の重複・欠落を考慮した Worker 実装が必要

------------------------------------------------------------------------

# 12. 保存動作

## latest / oneshot

保存戦略:

    DELETE all rows
    INSERT new rows

## snapshot

保存戦略:

    DELETE rows WHERE qrs_bucket_at = bucket_at
    INSERT new rows

Snapshot の結果セットは **0..N 行** を取り得ます。

------------------------------------------------------------------------

# 13. メタデータ列

QRS は `qrs_` プレフィックス付きの内部メタデータ列を追加します。

例:

    qrs_bucket_at
    qrs_ingested_at
    qrs_run_id

このプレフィックスはユーザークエリ列との衝突を防ぎます。

------------------------------------------------------------------------

# 14. スキーマ処理

### 列ソース

列名は以下から取得します:

    columns.name

スキーマ用途では Redash の `friendly_name` は無視します。

### スキーマ固定

スキーマは **最初に成功した dataset 実行時** に固定されます。

### 許可される変更

-   列順の変更

### 許可されない変更（エラー）

-   列追加
-   列削除
-   列名変更

スキーマ変更が発生した場合、実行は失敗し Run ログに記録されます。

------------------------------------------------------------------------

# 15. 列型

Redash の列型は**権威的な型情報としては信頼しません**。

QRS は:

-   Redash 列型を読み取る
-   ただし保存時の型付けは実装依存

例: - SQLite はすべてを動的に保存する場合がある。

## 15.1 AUTO 型マッピング方針（案）

`AUTO` 指定時は、Redash から取得した列型文字列を正規化して以下の型にマッピングする。

| Redash列型（正規化後） | SQLite | MySQL | PostgreSQL |
|---|---|---|---|
| `integer` / `bigint` / `long` | `INTEGER` | `BIGINT` | `BIGINT` |
| `float` / `double` / `real` | `REAL` | `DOUBLE` | `DOUBLE PRECISION` |
| `decimal` / `numeric` | `NUMERIC` | `DECIMAL(38,10)` | `NUMERIC` |
| `boolean` / `bool` | `INTEGER`（0/1） | `TINYINT(1)` | `BOOLEAN` |
| `date` | `TEXT`（`YYYY-MM-DD`） | `DATE` | `DATE` |
| `datetime` / `timestamp` | `TEXT`（`YYYY-MM-DD HH:MM:SS`） | `DATETIME(6)` | `TIMESTAMP` |
| `time` | `TEXT`（`HH:MM:SS`） | `TIME` | `TIME` |
| `json` / `object` / `array` | `TEXT`（JSON文字列） | `JSON`（非対応時は `LONGTEXT`） | `JSONB` |
| `string` / `text` / `unknown` / その他 | `TEXT` | `TEXT` | `TEXT` |

補足:

-   判定不能な型は安全側で `TEXT` にフォールバックする
-   実際の保存列型は、初回成功時のテーブル作成時に確定し、以後はスキーマロック対象とする
-   `AUTO` は将来の実装拡張であり、現行実装で `AUTO=TEXT` とする場合でもこの対応表を目標仕様として扱う

------------------------------------------------------------------------

# 16. 実行履歴（Run Log）

各実行には以下を保存します:

-   run_id
-   variant_id
-   bucket_at（snapshot のみ）
-   status
-   start_time
-   end_time
-   row_count（任意）
-   error message（失敗時）

目的:

-   監査
-   リトライロジック
-   catch-up 検出

------------------------------------------------------------------------

# 17. 即時実行の例外

以下の操作は即時実行される場合があります（GUI トリガー）:

-   Redash 接続テスト
-   Dataset クエリ検証（作成ステップ）

これらの実行は通常のバケット状態管理フローには入りません。

------------------------------------------------------------------------

# 18. テーブル命名

システムテーブル命名規約（QRS内部管理用）:

    qrs_sys_instances
    qrs_sys_datasets
    qrs_sys_variants
    qrs_sys_buckets
    qrs_sys_logs
    qrs_sys_schema

主要カラム（最小）:

-   `qrs_sys_buckets`
    - `variant_id`, `bucket_at`（複合主キー）
    - `status`, `priority`, `execute_after`
    - `attempt_count`, `last_error`
    - `last_row_count`, `last_fetch_seconds`（一覧で「直近件数」「取得秒数」を確認するため）
    - `started_at`, `finished_at`, `created_at`, `updated_at`
-   `qrs_sys_logs`
    - `log_id`
    - `variant_id`, `bucket_at`
    - `status`, `row_count`, `fetch_seconds`
    - `level`, `message`, `context_json`, `created_at`
-   `qrs_sys_schema`
    - `variant_id`
    - `storage_table`
    - `locked_columns_json`, `locked_at`, `updated_at`

保存データテーブル命名規約（BI参照向け）:

    qrs_d_<dataset_id>_<variant_id>

注記:

-   `qrs_sys_` は運用上の権限分離・保守判別を容易にするための固定プレフィックス
-   `qrs_d_` はデータテーブル名を短く保つためのプレフィックス
-   1 Variant は 1 つの保存データテーブルのみを使用する（mode差は保存ルールで表現する）

物理スキーマ実装は環境依存です。

------------------------------------------------------------------------

# 19. Backfill

Backfill では過去バケットの再実行を可能にします。

特性:

-   最低優先度
-   大規模操作では複数バケット実行が発生する場合がある

------------------------------------------------------------------------

# 20. 非目標（Non-Goals）

QRS は意図的に以下を実装しません:

-   BI ダッシュボード
-   可視化ツール
-   クエリビルダー
-   SQL 書き換え
-   スキーマ自動マイグレーション
-   Redash クエリ探索 UI

------------------------------------------------------------------------

# 21. 想定利用パターン

1.  Redash クエリを作成
2.  QRS で Dataset を作成
3.  Variant を設定
4.  実行をスケジュール
5.  生成されたテーブルを BI ツールで利用

------------------------------------------------------------------------

# 要件仕様ここまで

実装方針は `qrs_implementation_plan.ja.md` を参照。
