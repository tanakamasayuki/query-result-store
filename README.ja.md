# Query Result Store (QRS)

QRS (Query Result Store) は、Redash クエリを定期実行し、結果を任意のDBに保存して後続のBI/分析で利用するための軽量PHPアプリケーションです。

English README: [README.md](README.md)

## QRS ができること

- 複数 Redash インスタンスの登録
- Dataset（`instance_id + query_id`）の登録
- Variant（実行モード、パラメーター、スケジュール）の定義
- worker による bucket ベースの dispatch / execute
- システムテーブルへの実行状態・ログ保存
- Variant ごとのデータテーブルへの結果保存

## QRS がしないこと

- ダッシュボード/可視化機能
- Redash SQL編集
- スキーママイグレーション機能

## 技術構成

- PHP（古めの環境でも動きやすい実装方針）
- PDO 対応DB:
  - SQLite
  - MySQL
  - PostgreSQL
- Redash API 連携（cURL）

## 必要要件

- PHP拡張:
  - `PDO`（必須）
  - `json`（必須）
  - `curl`（必須）
- PDOドライバ:
  - `pdo_sqlite` / `pdo_mysql` / `pdo_pgsql` のうち1つ以上

## ディレクトリ構成

- Web UI: `public/`
- Worker: `bin/worker.php`
- コアライブラリ: `lib/`
- 言語ファイル: `lang/`
- 実行時ファイル（sqlite/raw payload/log）: `var/`

## クイックスタート（ローカルPHP）

1. 設定ファイル作成:

   ```bash
   cp config.sample.php config.php
   ```

2. `config.php` で DB / timezone を設定（または環境変数を利用）

3. Web UI 起動:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

4. ブラウザでアクセス:

   ```text
   http://127.0.0.1:8080
   ```

5. Environment 画面で:

- Runtime チェック確認
- スキーマ初期化
- 必要に応じ Runtime 設定保存

## クイックスタート（Webサーバー公開）

公開時は `public/` だけをWeb公開対象にする構成を推奨します。

1. 推奨: `DocumentRoot` を `public/` に向ける

- 例: `/var/www/qrs/public`
- `lib/`, `bin/`, `config.php`, `var/` などが直接公開されません

2. 既存環境で `DocumentRoot` を変えづらい場合: Alias / location で `public/` を割り当てる

- Apache の `Alias` や Nginx の `location` で `/qrs` を `public/` にマッピング
- 既存サイト配下に組み込みやすい方法です

3. 参考（非推奨寄り）: ルート直下に置いて deny で保護

- `lib/`, `bin/`, `var/`, `config.php` への直接アクセス拒否設定が必要
- 設定漏れリスクがあるため、1か2を推奨します

## Worker 実行

1回実行:

```bash
php bin/worker.php
```

Cron 例（ホスト）:

```cron
* * * * * flock -n /var/lock/qrs-worker.lock php /path/to/query-result-store/bin/worker.php
```

## Docker クイックスタート

### 起動

```bash
docker compose up -d --build
```

- Web UI: `http://127.0.0.1:8080`
- Worker サービス（`worker`）はループ実行

### Worker ループ間隔

デフォルト: `15` 秒（`WORKER_LOOP_SECONDS=15`）

上書き例:

```bash
WORKER_LOOP_SECONDS=5 docker compose up -d worker
```

### 権限エラー時

`config.php` や `var/` への書き込み権限で失敗する場合:

```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

## Docker マウント

現在の `docker-compose.yml` では:

- ホスト: `docker-compose.yml` があるプロジェクトディレクトリ
- コンテナ: `/var/www/html`

例:

- ホスト `./var/qrs.sqlite3`
- コンテナ `/var/www/html/var/qrs.sqlite3`

Apache のドキュメントルートは `/var/www/html/public` です。

## 設定

QRS は `config.php` と環境変数の両方に対応しています。

コンテナ運用では環境変数を推奨します。

### 環境変数

- アプリ
  - `QRS_TIMEZONE`
- DB
  - `QRS_DB_DRIVER`（`sqlite` | `mysql` | `pgsql`）
  - `QRS_DB_SQLITE_PATH`
  - `QRS_DB_HOST`
  - `QRS_DB_PORT`
  - `QRS_DB_NAME`
  - `QRS_DB_USER`
  - `QRS_DB_PASSWORD`
  - `QRS_DB_CHARSET`（MySQL）

### Runtime 設定（`qrs_sys_meta`）

Environment UI から設定:

- `worker.global_concurrency`
- `worker.max_run_seconds`
- `worker.max_jobs_per_run`
- `worker.poll_timeout_seconds`
- `worker.poll_interval_millis`
- `worker.running_stale_seconds`
- `worker.retry_max_count`
- `worker.retry_backoff_seconds`
- `runtime.store_raw_redash_payload`
- `runtime.raw_redash_payload_dir`

## UI ページ

- `env.php`: 環境/Runtime/DB設定
- `instances.php`: Redashインスタンス
- `datasets.php`: Dataset
- `variants.php`: Variant（実行定義）
- `buckets.php`: Bucket状態
- `logs.php`: Workerログ

## 運用メモ

- Worker 起動時に stale な `running` を復旧
- 復旧後も `running` が残る場合は安全のため abort
- retry は非retryジョブより後に処理
- retry は上限回数 + 指数バックオフ

## 多言語対応

- 対応言語: 英語 / 日本語
- フォールバック順: 選択言語 -> `en` -> キーID
- 厳格モード: `QRS_APP_ENV=development` または `QRS_I18N_STRICT=1`
- 未定義キーのログ出力: `QRS_I18N_LOG_MISSING=1`

## 関連ドキュメント

- 要件仕様（日本語）: `qrs_requirements_spec.ja.md`
- 要件仕様（英語）: `qrs_requirements_spec.md`
- 実装計画（日本語）: `qrs_implementation_plan.ja.md`
- 実装計画（英語）: `qrs_implementation_plan.md`

## ライセンス

MIT. 詳細は [LICENSE](LICENSE)。
