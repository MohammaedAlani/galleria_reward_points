<?php

namespace App\Services;

use App\Helper\PhoneNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UltraMsgService
{
    private string $baseUrl;
    private string $instanceId;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ultramsg.base_url'), '/');
        $this->instanceId = (string) config('ultramsg.instance_id');
        $this->token = (string) config('ultramsg.token');

        if (!$this->instanceId || !$this->token) {
            throw new RuntimeException(
                'UltraMsg is not configured. Set ULTRAMSG_INSTANCE_ID and ULTRAMSG_TOKEN in your .env.'
            );
        }
    }

    public function getStatus(): array
    {
        return $this->get('instance/status');
    }

    public function getMe(): array
    {
        return $this->get('instance/me');
    }

    public function getSettings(): array
    {
        return $this->get('instance/settings');
    }

    public function sendChat(string $to, string $body): array
    {
        return $this->post('messages/chat', [
            'to' => $this->formatTo($to),
            'body' => $body,
        ]);
    }

    public function sendImage(string $to, string $imageUrl, ?string $caption = null): array
    {
        return $this->post('messages/image', [
            'to' => $this->formatTo($to),
            'image' => $imageUrl,
            'caption' => $caption ?? '',
        ]);
    }

    public function sendVideo(string $to, string $videoUrl, ?string $caption = null): array
    {
        return $this->post('messages/video', [
            'to' => $this->formatTo($to),
            'video' => $videoUrl,
            'caption' => $caption ?? '',
        ]);
    }

    public function sendLink(string $to, string $link, ?string $body = null): array
    {
        return $this->post('messages/link', [
            'to' => $this->formatTo($to),
            'link' => $link,
            'body' => $body ?? $link,
        ]);
    }

    private function formatTo(string $to): string
    {
        if (str_starts_with($to, '+')) {
            return PhoneNormalizer::forUltraMsg($to);
        }
        return $to;
    }

    private function get(string $path): array
    {
        return $this->handle(Http::get($this->url($path), [
            'token' => $this->token,
        ]));
    }

    private function post(string $path, array $payload): array
    {
        return $this->handle(Http::asForm()->post($this->url($path), array_merge(
            ['token' => $this->token],
            $payload
        )));
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . $this->instanceId . '/' . ltrim($path, '/');
    }

    private function handle(Response $response): array
    {
        $data = $response->json();
        $errorMessage = null;
        if (is_array($data) && isset($data['error'])) {
            $error = $data['error'];
            $errorMessage = is_scalar($error) ? (string) $error : json_encode($error, JSON_UNESCAPED_UNICODE);
        }

        if ($errorMessage && $this->isSubscriptionError($errorMessage)) {
            throw new UltraMsgSubscriptionException($errorMessage);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'UltraMsg request failed: ' . $response->status() . ' ' . ($errorMessage ?: $response->body())
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException('UltraMsg returned non-JSON response: ' . $response->body());
        }

        if ($errorMessage) {
            throw new RuntimeException('UltraMsg error: ' . $errorMessage);
        }

        return $data;
    }

    private function isSubscriptionError(string $message): bool
    {
        $needles = ['non-payment', 'Stopped', 'expired', 'subscription'];
        foreach ($needles as $n) {
            if (stripos($message, $n) !== false) {
                return true;
            }
        }
        return false;
    }
}
