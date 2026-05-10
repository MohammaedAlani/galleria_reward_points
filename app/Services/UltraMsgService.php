<?php

namespace App\Services;

use App\Helper\PhoneNormalizer;
use App\Models\WhatsappSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UltraMsgService
{
    private string $baseUrl;
    private string $instanceId;
    private string $token;

    public function __construct(?WhatsappSetting $setting = null)
    {
        $setting ??= WhatsappSetting::current();

        if (!$setting->instance_id || !$setting->token) {
            throw new RuntimeException('UltraMsg instance is not configured.');
        }

        $this->baseUrl = rtrim(config('ultramsg.base_url'), '/');
        $this->instanceId = $setting->instance_id;
        $this->token = $setting->token;
    }

    public function getQr(): array
    {
        return $this->get('instance/qrCode');
    }

    public function getStatus(): array
    {
        return $this->get('instance/status');
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
        if ($response->failed()) {
            throw new RuntimeException(
                'UltraMsg request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('UltraMsg returned non-JSON response: ' . $response->body());
        }

        if (isset($data['error'])) {
            throw new RuntimeException('UltraMsg error: ' . json_encode($data['error']));
        }

        return $data;
    }
}
