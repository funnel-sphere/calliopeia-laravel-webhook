# Laravel連携サンプル

Laravelアプリへコピーして使う、小さなArtisanコマンドのサンプルです。
公開プラグイン **0.1.0以降**で、音声のアップロード・解析投入・結果取得とWebhook受信を動かせます。
このサンプルは0.1.0タグ発行後に`main`へ追加したため、以下ではリポジトリもcloneします。
ライセンスはリポジトリと同じ[MIT](../../LICENSE)です。

## セットアップ

PHP 8.3以降・Composer・SQLite拡張を用意します。以下は新しいLaravel 13アプリの例です。
既存のLaravel 12/13アプリでは、ファイル名・テーブル名の衝突を確認してからコピーしてください。

```bash
git clone https://github.com/funnel-sphere/calliopeia-laravel-webhook.git
composer create-project laravel/laravel calliopeia-example "^13.0"
cd calliopeia-example
composer config repositories.calliopeia-webhook vcs https://github.com/funnel-sphere/calliopeia-laravel-webhook
composer require funnelsphere/calliopeia-laravel-webhook:^0.1 --with-all-dependencies
cp -R ../calliopeia-laravel-webhook/samples/laravel/app/. app/
cp ../calliopeia-laravel-webhook/samples/laravel/database/migrations/*.php database/migrations/
php artisan vendor:publish --tag=calliopeia-config
php artisan vendor:publish --tag=calliopeia-webhook-config
```

[.env.example](.env.example)の項目をアプリの`.env`へ追記し、プレースホルダーを自分の環境の値に置き換えます。
アプリの既存`.env`や`APP_KEY`を上書きしないでください。新規Laravelアプリが用意するSQLite、
cache、jobsテーブルを使います。既存アプリにそれらのmigrationがない場合はLaravelの
`make:cache-table` / `make:queue-table`を使って用意します。

```bash
php artisan config:clear
php artisan migrate
php artisan list calliopeia
```

API用テナントキーには`jobs:create`と`jobs:read`が必要です。
プラグイン0.1.xはGuzzle 7を使用するため、新規アプリにGuzzle 8が入っている場合は
上のComposer操作で7系へ切り替わります。既存アプリでは依存の変更内容を確認してください。
Webhook tokenはAPI用キーと別に生成し、両者ともGitへ登録しません。

## 音声の送信・再試行・結果

手元の検証用音声を指定します。実サービスの解析を実行するため、通常の利用枠・料金が適用されます。
`--seconds`は実際の録音秒数です。音声や結果のサンプルデータは同梱していません。

```bash
php artisan calliopeia:submit recording-001 /absolute/path/to/take.m4a --seconds=12.5
php artisan calliopeia:result recording-001
# COMPLETEDになってから本文を表示する（端末・ログの閲覧者に注意）
php artisan calliopeia:result recording-001 --show
```

送信コマンドは受付後のjob IDを表示します。`result`は一度だけ現在の状態を取得します。
`PENDING`や`PROCESSING`なら時間を置いて同じコマンドを実行してください。
終了コード0でも解析完了とは限らないため、表示された状態を確認します。
既定は品質優先・個別カルテOff・BGM除去Offです。

応答が途切れた場合は、**同じrequestIdだけ**で再試行します。

```bash
php artisan calliopeia:submit recording-001
```

送信前に入力条件をDBへ保存し、アップロード後はobjectKeyを保存してから解析を投入します。
投入時の応答が失われても、同じobjectKey・冪等キーで再試行します。
既にjob IDを保存できた場合はそのIDを返します。別の音声には必ず新しいrequestIdを使います。
アップロード完了をDBへ保存する前に中断した場合は、解析投入前なのでアップロードをやり直します。
元の音声が変わっていたら再送を拒否します。署名付きuploadUrlはDBへ残しません。

`calliopeia_submissions`のrequest/ticket/resultは`APP_KEY`で暗号化して保存します。
`APP_KEY`とDBは一緒に保護し、元音声・結果の保持期限を自分の運用で定めてください。
処理中の同一requestIdは15分のcache lockで排他します。複数サーバーでは共有のdatabase/Redisを使い、
サンプルの既定API timeoutを延長するときはlockの有効時間も見直してください。

## Webhookを使う場合

Calliopeiaへ公開HTTPS URL `https://your-host.example/api/calliopeia/webhooks`を登録します。
通知形式`SUMMARY_CALLBACK_V1`、認証`BEARER`、tokenを`.env`と一致させます。
取得したendpoint IDで新しい音声を送信します。

```bash
php artisan queue:work database --queue=calliopeia-webhooks
# 別の端末で実行
php artisan calliopeia:submit recording-002 /absolute/path/to/take.m4a --seconds=12.5 --webhook-endpoint=your-endpoint-id
php artisan calliopeia:receipts
```

受信本文はプラグインが暗号化してDBへ保存し、queue workerが処理します。
`receipts`は最新10件のID・処理状態・処理日時を表示します。
業務DBへの転記はこのサンプルでは行いません。実アプリでは
[ルートREADMEのListener例](../../README.md#業務処理への接続)を追加し、転記も冪等にしてください。
同じイベントを再送してReceiptと業務処理が増えないことまで確認します。

ローカルの`php artisan serve`だけではCalliopeiaから到達できません。
localhostへの保存結果の再送は受信側のHTTP・DB・queue確認であり、公開Endpointへの配信確認とは区別します。

## 含めていないもの

Web画面、利用者認証、音声録音、定期ポーリング、課金を伴う書き起こし購入は含めません。
既存Laravelアプリの認証・権限・業務モデルに合わせて組み込んでください。
追加質問や書き起こし取得は[APIクライアントの説明](../../README.md#書き起こしと追加質問)を参照してください。

Laravelの仕組みは公式の[Artisan](https://laravel.com/docs/13.x/artisan)、
[Atomic Locks](https://laravel.com/docs/13.x/cache#atomic-locks)に沿っています。
