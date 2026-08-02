# Calliopeia Laravel Webhook Receiver

[![Tests](https://github.com/funnel-sphere/calliopeia-laravel-webhook/actions/workflows/tests.yml/badge.svg)](https://github.com/funnel-sphere/calliopeia-laravel-webhook/actions/workflows/tests.yml)

Calliopeiaの解析結果WebhookをLaravelで安全に受信するための参照パッケージです。Calliopeia送信実装の次の契約に対応します。

- `SUMMARY_CALLBACK_V1` / `EVENT_ENVELOPE_V1`
- `BEARER` / `SIGNED_JWT` / `RSA_SIGNATURE` / `BODY_ACCESS_TOKEN` / `NONE`
- `X-Calliopeia-Event-Id`または`summary_id`による冪等処理
- HTTP 200、401、404、413、415、422、429、500の受信側セマンティクス
- 暗号化した本文保存とキュー処理

標準連携には`SUMMARY_CALLBACK_V1 + BEARER`を推奨します。

このリポジトリはWebhook受信側だけを実装し、Calliopeia本体、モデル処理、実データ、認証情報は含みません。

## 導入

Packagist登録前はGitHubリポジトリをComposerのVCS repositoryとして追加します。

```bash
composer config repositories.calliopeia-webhook vcs https://github.com/funnel-sphere/calliopeia-laravel-webhook
composer require funnelsphere/calliopeia-laravel-webhook:dev-main
php artisan vendor:publish --tag=calliopeia-webhook-config
php artisan migrate
```

Laravel 12（PHP 8.2以降）とLaravel 13（PHP 8.3以降）を対象にしています。パッケージのService ProviderはComposer discoveryで自動登録されます。

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

テストは正常・失敗要約、イベントEnvelope、Bearer、本文トークン、RSA署名、署名JWT、重複、改変衝突、暗号化保存、404ポリシー、Queue再試行を対象にします。

## License

[MIT License](LICENSE)
