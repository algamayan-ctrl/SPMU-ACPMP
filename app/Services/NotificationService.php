<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    /** @param iterable<User> $recipients */
    public function send(string $eventCode, iterable $recipients, string $message, ?Model $source = null, array $channels = ['SYSTEM', 'EMAIL', 'SMS']): NotificationEvent
    {
        $event = NotificationEvent::query()->create([
            'event_code' => $eventCode,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'created_by_user_id' => auth()->id(),
            'payload_snapshot_json' => ['message' => $message],
            'occurred_at' => now(),
        ]);

        foreach ($recipients as $recipient) {
            foreach ($channels as $channel) {
                $address = match ($channel) {
                    'EMAIL' => $recipient->email,
                    'SMS' => $recipient->mobile_no,
                    default => (string) $recipient->id,
                };
                [$status, $provider, $providerResponse] = $this->deliver($channel, $address, $message, $eventCode);

                NotificationDelivery::query()->create([
                    'notification_event_id' => $event->id,
                    'recipient_user_id' => $recipient->id,
                    'channel' => $channel,
                    'address_snapshot' => $address,
                    'attempt_no' => 1,
                    'provider' => $provider,
                    'attempted_at' => now(),
                    'delivery_status' => $status,
                    'provider_response' => $providerResponse,
                ]);
            }
        }

        Log::info("SPMU notification {$eventCode}: {$message}");

        return $event;
    }

    /** @return array{string, ?string, string} */
    private function deliver(string $channel, ?string $address, string $message, string $eventCode): array
    {
        if ($channel === 'SYSTEM') {
            return ['SENT', 'system', 'Stored in the authenticated in-system notification record.'];
        }
        if (blank($address)) {
            return ['FAILED', strtolower($channel), 'Recipient address is not configured.'];
        }

        if ($channel === 'EMAIL') {
            try {
                Mail::raw($message, function (Message $mail) use ($address, $eventCode): void {
                    $mail->to($address)->subject(config('app.name').' - '.str_replace('_', ' ', $eventCode));
                });

                return ['SENT', (string) config('mail.default'), 'Accepted by the configured Laravel mail transport.'];
            } catch (Throwable $exception) {
                Log::warning('SPMU email delivery failed', ['event' => $eventCode, 'error' => $exception->getMessage()]);

                return ['FAILED', (string) config('mail.default'), mb_substr($exception->getMessage(), 0, 1000)];
            }
        }

        $provider = SystemSetting::value('sms_provider') ?: config('services.sms.provider');
        $url = config('services.sms.webhook_url');
        if (blank($provider) || blank($url)) {
            return ['FAILED', $provider, 'SMS provider/webhook is not configured; system and email delivery remain available.'];
        }

        try {
            $request = Http::timeout(10)->acceptJson();
            if (filled(config('services.sms.token'))) {
                $request = $request->withToken((string) config('services.sms.token'));
            }
            $response = $request->post($url, ['to' => $address, 'message' => $message, 'event_code' => $eventCode]);

            return [$response->successful() ? 'SENT' : 'FAILED', (string) $provider, 'HTTP '.$response->status().' '.mb_substr($response->body(), 0, 900)];
        } catch (Throwable $exception) {
            Log::warning('SPMU SMS delivery failed', ['event' => $eventCode, 'error' => $exception->getMessage()]);

            return ['FAILED', (string) $provider, mb_substr($exception->getMessage(), 0, 1000)];
        }
    }
}
