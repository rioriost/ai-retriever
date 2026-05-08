# WP Retriever

WP Retriever は、WordPress の検索に RAG 風のベクトル検索を追加する実験的なプラグインです。

公開済み投稿の本文を埋め込みベクトル化し、WordPress のデータベース内に保存します。検索時は、通常の WordPress 検索とベクトル検索の結果を組み合わせて、関連性の高い投稿を表示します。

## 主な機能

- 投稿、固定ページ、カスタム投稿タイプの埋め込み作成
- MariaDB の native vector 型を使ったベクトル検索
- 通常の WordPress 検索との統合
- `[RAG]` / `[標準検索]` バッジ表示
- OpenAI / Azure OpenAI / Mistral / Jina AI / Voyage AI / Cohere / Ollama / LM Studio / Infinity / TEI / Custom HTTP 対応
- 日本語クエリー正規化
- カスタムフィールド、分類の追加インデックス対象指定
- バックグラウンド初期化
- インデックス診断
- ライブベクトルクエリーテスト

## 動作要件

### WordPress / PHP

- WordPress 6.0 以上
- PHP 8.0 以上

### データベース

正式な対象は次です。

- MariaDB 11.7 以上
  - `VECTOR(n)`
  - `VECTOR INDEX`
  - `VEC_FromText()`
  - `VEC_DISTANCE_COSINE()` / `VEC_DISTANCE_EUCLIDEAN()`

MySQL 9.x は候補として検出しますが、現時点では既定で native vector search を無効にしています。MySQL の vector 型、index、距離関数、optimizer の挙動は環境差があるため、検証済み環境以外ではサポート対象外です。

MariaDB 11.7 未満、MySQL 8.x はサポート対象外です。

## インストール方法

### ZIP からインストール

1. 配布 ZIP `wp-retriever-0.1.1.zip` を用意します。
2. WordPress 管理画面で `プラグイン -> 新規追加 -> プラグインのアップロード` を開きます。
3. ZIP をアップロードします。
4. `WP Retriever` を有効化します。
5. `設定 -> WP Retriever` を開きます。

### 手動インストール

1. `wp-retriever` ディレクトリを `wp-content/plugins/` に配置します。
2. WordPress 管理画面の `プラグイン` で `WP Retriever` を有効化します。
3. `設定 -> WP Retriever` を開きます。

## 初期設定の流れ

`設定 -> WP Retriever` は、作業順に番号付きで並んでいます。

### 1. データベースのチェック

まず `データベースをチェック` を実行します。

このチェックでは、一時的な3次元ベクトルテーブルを作成し、動作をチェックして、テーブルを削除します。

成功すれば、現在のデータベースで native vector search を使える可能性が高い状態です。

### 2. RAG 検索設定

#### RAG 検索モード

- `オフ`
  - RAG 検索を使いません。
- `管理者のみ`
  - 管理者だけ RAG 検索を使います。検証向けです。
- `フル`
  - 通常の検索画面で RAG 検索を使います。

#### 検索元バッジ

有効にすると、検索結果のタイトルに `[RAG]` / `[標準検索]` を表示します。

#### 埋め込みプロバイダー

既定は次です。

- Provider: `OpenAI`
- Model: `text-embedding-3-small`
- Dimensions: `1536`

対応 provider:

- OpenAI
- Azure OpenAI
- Mistral
- Jina AI
- Voyage AI
- Cohere
- Ollama
- LM Studio
- Infinity
- TEI
- Custom HTTP

Provider を選ぶと、モデル候補、エンドポイント、次元数が自動入力されます。必要に応じて修正してください。

#### OpenAI

- `text-embedding-3-small` / `1536`
- `text-embedding-3-large` / `3072`

API キーを入力してください。

#### Azure OpenAI

Azure OpenAI は deployment ごとに endpoint が違います。

候補を選んだ後、エンドポイントを実際の Azure resource / deployment に合わせて書き換えてください。

例:

- `https://YOUR-RESOURCE.openai.azure.com/openai/deployments/YOUR-DEPLOYMENT/embeddings?api-version=2024-02-01`

API キー欄には Azure OpenAI の API キーを入力します。

#### Hosted provider

利用しやすい hosted provider 候補:

- Mistral: `mistral-embed`
- Jina AI: `jina-embeddings-v3`
- Voyage AI: `voyage-3-large`, `voyage-3`
- Cohere: `embed-v4.0`, `embed-multilingual-v3.0`

それぞれの API キーを入力してください。

#### ローカル / self-hosted provider

ローカルで embedding server を動かす場合の候補:

- Ollama
  - `nomic-embed-text`
  - `mxbai-embed-large`
- LM Studio
- Infinity
- TEI
- Custom HTTP

ローカルサービスを使う場合は、テスト前にサービスが起動していることを確認してください。

### 3. 埋め込みプロバイダーのチェック

`埋め込みプロバイダーをチェック` を実行します。

初期化の前に、現在のプロバイダー設定で埋め込みリクエストを1回実行します。

結果には次が表示されます。

- 成功 / 失敗
- provider
- model
- dimensions
- elapsed time

API キーは表示しません。

OpenAI などの外部 API を使う場合、少額の API 利用料が発生する可能性があります。

### 4. 日本語クエリー正規化設定

`日本語クエリー正規化` を有効にすると、全角/半角や大文字/小文字の揺らぎを文字列検索に追加し、ベクトルのテスト/検索クエリーを正規化します。

既定は OFF です。

有効にすると、次の追加設定が表示されます。

#### インデックス対象のカスタムフィールド

任意。メタキーを1行に1つ、またはカンマ区切りで指定します。

値は埋め込みプロバイダーに送信されるため、個人情報は含めないでください。

#### インデックス対象の分類基準

任意。分類の識別子を1行に1つ、またはカンマ区切りで指定します。

例:

- `category`
- `post_tag`

### 5. 初期化

`初期化` を実行すると、既存の公開済みコンテンツの埋め込みを作成し、ローカルのベクトルテーブルに保存します。

初回のロードには時間がかかります。投稿数が多い場合や外部の埋め込み API を使う場合は特に時間がかかります。

初期化はバックグラウンドキューで処理されます。画面には進捗が表示されます。

埋め込みプロバイダー、モデル、次元数、インデックス対象の設定を変更した場合は、再度初期化してください。

### 6. インデックス診断

次の情報を確認できます。

- 対象投稿数
- インデックス済み投稿数
- カバー率
- ベクトルチャンク数
- 失敗した投稿数
- キュー状態
- インデクシング失敗リスト

失敗した投稿がある場合は、個別または一括で再試行できます。

### 7. ライブベクトルクエリーテスト

任意のテストクエリーを入力して、現在のベクトル検索パイプラインで検索を試せます。

表示される内容:

- post ID
- title
- score
- 最適チャンク抜粋

検索品質やインデックス内容の確認に使います。

## WP-CLI

同じ初期化キューは WP-CLI からも操作できます。

- `wp retriever backfill --start`
- `wp retriever backfill --status`
- `wp retriever backfill --batch-size=20`
- `wp retriever backfill --all`

## アンインストール

プラグインをアンインストールすると、次のデータを削除します。

- plugin settings
- 初期化キュー
- ログ
- query cache transient
- live query transient
- post meta
  - `_wp_retriever_content_hash`
  - `_wp_retriever_indexed_at`
  - `_wp_retriever_last_error`
- scheduled queue event
- local vector table

必要なデータがある場合は、アンインストール前にバックアップしてください。

## ディスクレーマー

このプラグインは実験的なソフトウェアです。

- すべての環境での動作を保証しません。
- 検索結果の正確性を保証しません。
- 外部埋め込み API の料金、障害、仕様変更について責任を負いません。
- 埋め込み API には投稿本文や指定したカスタムフィールドの内容が送信されます。
- 個人情報、機密情報、非公開情報を外部 API に送信しないよう注意してください。
- 本番環境で使う前に、ステージング環境で十分に検証してください。

## セキュリティとプライバシー

- API キーは WordPress options に保存されます。
- 設定画面では API キーを表示しません。
- パスワード付き投稿はインデックス対象外です。
- draft/private 投稿を対象にする場合は、外部 API への送信内容に注意してください。
- カスタムフィールドを追加対象にする場合は、個人情報や秘密情報を含めないでください。

## ライセンス

GPL v2 or later

詳細は `LICENSE` を参照してください。

## 開発者向け

配布用 ZIP は次で作成します。

- `make release`

`make release` は次を実行します。

1. Composer validation
2. PHP syntax lint
3. PHPCS security scan
4. Composer audit
5. Docker Compose config validation
6. ZIP packaging

出力例:

- `dist/wp-retriever-0.1.1.zip`

## 既知の制限

- MariaDB 11.7 以上を主な対象にしています。
- MySQL native vector 対応は既定では無効です。
- Hosted provider は各社 API の仕様変更に影響されます。
- Azure OpenAI は deployment endpoint を手動で設定する必要があります。
