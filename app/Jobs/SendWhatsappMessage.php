<?php

namespace App\Jobs;

use App\Models\WhatsappBroadcast;
use App\Models\WhatsappMessage;
use App\Services\UltraMsgService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(public int $messageId)
    {
    }

    public function handle(UltraMsgService $ultra): void
    {
        $message = WhatsappMessage::with('broadcast')->find($this->messageId);
        if (!$message || $message->status === 'sent') {
            return;
        }

        $broadcast = $message->broadcast;
        $to = $message->phone_normalized;

        try {
            $response = match ($broadcast->media_type) {
                'image' => $ultra->sendImage($to, $broadcast->media_url, $broadcast->body),
                'video' => $ultra->sendVideo($to, $broadcast->media_url, $broadcast->body),
                'link' => $ultra->sendLink($to, $broadcast->link_url, $broadcast->body),
                default => $ultra->sendChat($to, $broadcast->body ?? ''),
            };

            $message->update([
                'status' => 'sent',
                'ultramsg_message_id' => $response['id'] ?? ($response['sent'] ?? null) ? (string) ($response['id'] ?? '') : null,
                'sent_at' => now(),
            ]);

            $broadcast->increment('sent');
        } catch (Throwable $e) {
            Log::warning('WhatsApp send failed', [
                'message_id' => $message->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $message->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
                $broadcast->increment('failed');
                $this->maybeFinishBroadcast($broadcast);
                return;
            }

            throw $e;
        }

        $this->maybeFinishBroadcast($broadcast);
    }

    private function maybeFinishBroadcast(WhatsappBroadcast $broadcast): void
    {
        $broadcast->refresh();
        if (($broadcast->sent + $broadcast->failed) >= $broadcast->total && $broadcast->total > 0) {
            $broadcast->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }
    }
}
