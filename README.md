# Calliopeia Laravel API Client / Webhook Receiver

[![Tests](https://github.com/funnel-sphere/calliopeia-laravel-webhook/actions/workflows/tests.yml/badge.svg)](https://github.com/funnel-sphere/calliopeia-laravel-webhook/actions/workflows/tests.yml)

Calliopeiaの解析投入・結果取得と、解析結果Webhookの受信をLaravelへ組み込むパッケージです。
既存Webhookの設定・namespace・受信処理はそのまま利用できます。Webhookは次の契約に対応します。

- `SUMMARY_CALLBACK_V1` / `EVENT_ENVELOPE_V1`
- `BEARER` / `SIGNED_JWT` / `RSA_SIGNATURE` / `BODY_ACCESS_TOKEN` / `NONE`
- `X-Calliopeia-Event-Id`または`summary_id`による冪等処理
- HTTP 200、401、404、413、415、422、429、500の受信側セマンティクス
- 暗号化した本文保存とキュー処理

標準連携には`SUMMARY_CALLBACK_V1 + BEARER`を推奨します。

このリポジトリにCalliopeia本体、モデル処理、実データ、認証情報は含みません。

## 導入

Packagist登録前はGitHubリポジトリをComposerのVCS repositoryとして追加します。

```bash
composer config repositories.calliopeia-webhook vcs https://github.com/funnel-sphere/calliopeia-laravel-webhook
composer require funnelsphere/calliopeia-laravel-webhook:^0.1
php artisan vendor:publish --tag=calliopeia-config
php artisan vendor:publish --tag=calliopeia-webhook-config
php artisan migrate
```

Laravel 12（PHP 8.2以降）とLaravel 13（PHP 8.3以降）を対象にしています。
パッケージのService ProviderはComposer discoveryで自動登録されます。
APIクライアントは`config/calliopeia.php`、Webhook受信は`config/calliopeia-webhook.php`を使います。

## APIクライアント（0.1.0以降）

APIクライアントとWebhook受信機能を0.1.0で提供します。既存のWebhook設定はそのまま利用できます。

```dotenv
CALLIOPEIA_GRAPHQL_URL=https://<AppSyncのホスト>/graphql
CALLIOPEIA_APPSYNC_API_KEY=<環境の公開AppSyncキー>
CALLIOPEIA_PULL_URL=https://<結果取得APIのホスト>
CALLIOPEIA_API_KEY=<jobs:createとjobs:readを持つテナントAPIキー>
```

送信側APIキーは`CALLIOPEIA_WEBHOOK_TOKEN`とは別です。サーバーの環境変数・Secret Managerで
管理し、ブラウザーやiOSへ渡さないでください。Laravel側はサーバー用APIキー認証を包みます。
利用者のメールOTPログインはiOS SDKの`CalliopeiaSession`を使います。

```php
use FunnelSphere\CalliopeiaWebhook\Client\CalliopeiaClient;

$api = app(CalliopeiaClient::class);
$accepted = $api->submitAudio(
    path: storage_path('app/recordings/take.m4a'),
    idempotencyKey: $savedRequestId,
    audioSeconds: $duration,
    options: [
        'generateIndividualKartes' => false,
        'passthrough' => ['external_record_id' => $recordId],
    ],
);
$jobId = $accepted['job']['id'];
// 後続のQueue jobや定期処理で取得する。受付成功だけでは解析は完了していない。
$result = $api->getJob($jobId);
$status = $result['job']['status'];
if ($status === 'COMPLETED') {
    $summary = $result['job']['result'];
    $visits = $api->getVisits($jobId);
    $sections = $visits['visits']['visits']['sections'];
}
// 処理中なら時間を置いて同じjobIdを取得する。失敗状態ならアプリ側で扱う。
```

既定は`quality_batch`、個別カルテOff、BGM除去Off、結果取得方式は`ASYNC`です。Onにする場合だけ
`generateIndividualKartes => true`を指定します。Offでも録音全体のカルテ・
接客の区切り・書き起こし・追加質問を利用できます。別プロファイルにはこれらの
品質優先専用オプションを自動付加しません。音声は署名付きURLへストリーム送信します。

`$savedRequestId`はアプリ側で保存した一意な文字列（1〜128バイト）、
`$duration`は録音の秒数です。各ジョブのIDも保存してください。
`submitAudio`はアップロードから投入までを一度実行する便利メソッドです。
投入後に応答が不明になった場合の再送には、**同じアップロード済みticketと同じidempotencyKey**が必要です。
再送するアプリでは下の分割手順を使い、ticketを保持して`invokeAudioJob`だけを再試行してください。
`submitAudio`を再び呼ぶと別のobjectKeyが発行され、同じ冪等キーでは衝突します。
署名付きuploadUrl自体はログへ出さず、
保持期間を短くしてください。

```php
$ticket = $api->createAudioUpload('take.m4a', 'audio/mp4');
$api->uploadAudio($localPath, $ticket);
$accepted = $api->invokeAudioJob(
    $ticket, 'take.m4a', filesize($localPath), $savedRequestId, $duration,
    ['responseMode' => 'WEBHOOK', 'webhookEndpointId' => $endpointId],
);
```

### 書き起こしと追加質問

```php
$provisional = $api->getProvisionalTranscript($jobId);
$offer = $api->getFormattedTranscript($jobId);
// OFFERの金額を利用者へ提示し、同意を取得したときだけ実行する。
if ($offer['state'] === 'OFFER' && $userAcceptedCharge) {
    $formatted = $api->purchaseFormattedTranscript($jobId, $offer['quoteToken'], true);
}

$question = $api->askQuestion(
    jobId: $jobId, question: '修理の完了予定は？', requestId: $savedQuestionRequestId,
    parentQuestionId: null, sectionIndex: null,
);
$answer = $api->getQuestion($jobId, $question['question']['questionId']);
$history = $api->getQuestions($jobId, $nextToken);
```

HTTP 202は処理中です。レスポンスをそのまま返すため、ジョブと質問の`status`、
書き起こしの`state`を確認し、時間を置いて取得してください。暗黙の再投入や
課金同意は行いません。追加質問で個別カルテを生成したり、整形版を自動購入したりしません。
`sectionIndex`は省略時が録音全体、`0`が最初の接客です。

HTTP失敗は`CalliopeiaApiException`となり、`statusCode`、`requestId`、
`retryAfterSeconds`を参照できます。例外にHTTP本文・トークンは含めません。
設定不足や入力不正は`InvalidArgumentException`、
ネットワーク断の例外はLaravel HTTP clientの例外です。自動再送は行わないため、
投入・質問の冪等キーを保持してアプリ側で再試行してください。

## 最小設定

Laravelアプリの`.env`へ設定します。トークンはCalliopeiaのWebhook Endpointへ登録した値と同じものです。

```dotenv
CALLIOPEIA_WEBHOOK_ENABLED=true
CALLIOPEIA_WEBHOOK_PATH=api/calliopeia/webhooks
CALLIOPEIA_WEBHOOK_PAYLOAD_PROFILE=SUMMARY_CALLBACK_V1
CALLIOPEIA_WEBHOOK_AUTH_MODE=BEARER
CALLIOPEIA_WEBHOOK_TOKEN=<32バイト以上のランダム値>
CALLIOPEIA_WEBHOOK_QUEUE_CONNECTION=redis
CALLIOPEIA_WEBHOOK_QUEUE=calliopeia-webhooks
```

Calliopeia管理画面では次のように登録します。

| 項目 | 値 |
| --- | --- |
| URL | `https://<Laravelのホスト>/api/calliopeia/webhooks` |
| Method | `POST` |
| 通知形式 | `SUMMARY_CALLBACK_V1` |
| 認証 | `BEARER` |
| Token | Laravelの`CALLIOPEIA_WEBHOOK_TOKEN`と同じ値 |

キューワーカーを起動してください。

```bash
php artisan queue:work --queue=calliopeia-webhooks
```

## 業務処理への接続

既定ハンドラーは`CalliopeiaWebhookReceived`イベントをキューワーカー内で発行します。Listenerでは暗号化保存されたReceiptをIDで取得します。

```php
<?php

namespace App\Listeners;

use FunnelSphere\CalliopeiaWebhook\Events\CalliopeiaWebhookReceived;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;

final class StoreCalliopeiaSummary
{
    public function handle(CalliopeiaWebhookReceived $event): void
    {
        $receipt = WebhookReceipt::query()->findOrFail($event->receiptId);
        $payload = $receipt->payload;

        // summary_idで自社レコードを更新する。Listener自身も冪等に実装する。
    }
}
```

イベントではなく専用クラスへ直接渡す場合は、`WebhookHandler`を実装して公開済み設定の`handler`へ指定します。

```php
<?php

namespace App\Calliopeia;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookHandler;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;

final class StoreSummaryHandler implements WebhookHandler
{
    public function handle(WebhookReceipt $receipt): void
    {
        // $receipt->payloadは復号済み配列。
    }
}
```

```php
// config/calliopeia-webhook.php
'handler' => \App\Calliopeia\StoreSummaryHandler::class,
```

## 未知のsummary_idを404にする

受信前に自社DBを確認する場合は`WebhookAcceptancePolicy`を実装します。ここで404を返すとCalliopeiaは恒久エラーとして扱い、自動再送しません。

```php
<?php

namespace App\Calliopeia;

use App\Models\VoiceSummary;
use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookAcceptancePolicy;
use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;

final class KnownSummaryPolicy implements WebhookAcceptancePolicy
{
    public function assertAcceptable(IncomingWebhook $webhook): void
    {
        if ($webhook->summaryId !== null
            && !VoiceSummary::query()->whereKey($webhook->summaryId)->exists()) {
            throw WebhookRequestException::notFound();
        }
    }
}
```

```php
// config/calliopeia-webhook.php
'acceptance_policy' => \App\Calliopeia\KnownSummaryPolicy::class,
```

## 認証方式

| Mode | Laravel側の追加設定 | 検証内容 |
| --- | --- | --- |
| `BEARER` | `CALLIOPEIA_WEBHOOK_TOKEN` | 固定トークンを定数時間比較 |
| `BODY_ACCESS_TOKEN` | 同上 | `access_token`を比較し、保存前に削除 |
| `RSA_SIGNATURE` | 公開鍵、任意のkey ID | `timestamp + "." + rawBody`のRSA-SHA256署名 |
| `SIGNED_JWT` | 公開鍵、audience、任意のkey ID | RS256、`iss/aud/sub/iat/exp/jti/summary_id` |
| `NONE` | `CALLIOPEIA_WEBHOOK_ALLOW_NONE=true` | 明示許可時のみ。通常は使用しない |

PEMは環境変数へ直接入れるより、Secret Manager等からファイルへ展開し、パスを渡す方法を推奨します。

```dotenv
CALLIOPEIA_WEBHOOK_AUTH_MODE=RSA_SIGNATURE
CALLIOPEIA_WEBHOOK_PUBLIC_KEY_PATH=/run/secrets/calliopeia-webhook-public.pem
CALLIOPEIA_WEBHOOK_KEY_ID=<Calliopeiaに表示されるkey ID>
```

`SIGNED_JWT`では次も必要です。

```dotenv
CALLIOPEIA_WEBHOOK_AUTH_MODE=SIGNED_JWT
CALLIOPEIA_WEBHOOK_AUDIENCE=https://partner.example/api/calliopeia/webhooks
CALLIOPEIA_WEBHOOK_ISSUER=calliopeia
```

`BODY_ACCESS_TOKEN`は`EVENT_ENVELOPE_V1`専用です。`SUMMARY_CALLBACK_V1`との組み合わせには使いません。

## 応答と再送

| HTTP | この実装での意味 | Calliopeia側 |
| --- | --- | --- |
| 200 | DBへ受付済み。重複も200 | 配信完了 |
| 401 | 認証、署名、時刻検証エラー | 恒久失敗 |
| 404 | Acceptance Policyが対象なしと判定 | 恒久失敗 |
| 413 | 本文上限超過 | 恒久失敗 |
| 415 | JSON以外 | 恒久失敗 |
| 422 | JSON契約違反、同一冪等キーで本文が変化 | 恒久失敗 |
| 429 | Laravel rate limiter | `Retry-After`に従って再送 |
| 500 | DBまたはQueueの一時障害、設定不備 | 自動再送 |

`SUMMARY_CALLBACK_V1`では`summary:<summary_id>`、`EVENT_ENVELOPE_V1`では`event:<X-Calliopeia-Event-Id>`をDBの一意キーにします。同じキーで本文ハッシュが異なる場合は、重複として握りつぶさず422を返します。

## セキュリティと運用

- Webhook本文はLaravelの`APP_KEY`で暗号化して保存します。`APP_KEY`を失うと復号できません。
- Bearer token、本文中access token、秘密鍵はDBへ保存しません。
- `X-Calliopeia-Timestamp`は既定で現在時刻から前後10分以内だけ受け入れます。
- Webhook ControllerはDB保存とQueue投入だけを行い、業務処理をHTTPリクエスト内で実行しません。
- Queue jobはReceipt単位で一意化し、DB状態でも二重処理を防ぎます。最終的な自社DB更新も`summary_id`で冪等にしてください。
- Nginx、ALB、WAF、PHPの本文上限も`CALLIOPEIA_WEBHOOK_MAX_BODY_BYTES`以上かつ必要最小限に揃えてください。
- Receiptには要約や転写などの個人データが含まれ得ます。保持期間を自社規程に合わせ、定期削除してください。

## テスト

```bash
composer install
composer test
```

開発テストは正常・失敗要約、イベントEnvelope、Bearer、本文トークン、RSA署名、署名JWT、重複、改変衝突、暗号化保存、404ポリシー、Queue再試行を対象にします。
APIクライアントの開発テストはHTTPをfakeしており、実サービスの接続確認にはなりません。

実サービスへの統合確認では、自分の環境の接続設定と検証用音声を使い、
`submitAudio`または`createAudioUpload → uploadAudio → invokeAudioJob`で投入して、
次を確認してください。処理料金が発生する環境では通常の利用枠を使います。

1. 同じ冪等キーでの再送が同じjob IDを返すこと。
2. そのjobが完了し、`getJob`の結果と`getProvisionalTranscript`の内容が入力音声に対応すること。
3. `getVisits`が個別カルテOffを維持し、`askQuestion → getQuestion`で完了した回答と引用を取得できること。
4. Webhookを使う場合は実際のEndpointへ通知し、ReceiptのDB保存、Queue処理、同一イベント再送後も業務処理が重複しないこと。

保存した実結果をlocalhostへ再送する確認は、Laravel受信側のHTTP・DB・Queueの確認です。
Calliopeiaから公開Endpointまでの配信確認は別途必要です。

## License

[MIT License](LICENSE)
