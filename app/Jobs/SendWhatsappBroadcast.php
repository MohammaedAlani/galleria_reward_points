<?php

namespace App\Jobs;

use App\Models\WhatsappBroadcast;
use App\Models\WhatsappMessage;
use App\Services\RecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsappBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $broadcastId)
    {
    }

    public function handle(RecipientResolver $resolver): void
    {
        $broadcast = WhatsappBroadcast::find($this->broadcastId);
        if (!$broadcast) {
            return;
        }

        $broadcast->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $recipients = $resolver->resolve($broadcast->recipient_filter ?? []);
        } catch (\Throwable $e) {
            Log::error('WhatsApp broadcast resolve failed', [
                'broadcast_id' => $broadcast->id,
                'error' => $e->getMessage(),
            ]);
            $broadcast->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
            return;
        }

        $delaySeconds = 0;
        $perMinute = max(1, (int) config('ultramsg.throttle_per_minute', 60));
        $intervalMs = (int) ceil(60000 / $perMinute);

        $total = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            $total++;

            $message = WhatsappMessage::create([
                'broadcast_id' => $broadcast->id,
                'customer_id' => $r['customer_id'],
                'phone_raw' => $r['phone_raw'],
                'phone_normalized' => $r['phone_normalized'],
                'status' => $r['phone_normalized'] ? 'queued' : 'failed',
                'error' => $r['phone_normalized'] ? null : 'Invalid phone',
            ]);

            if (!$r['phone_normalized']) {
                $failed++;
                continue;
            }

            SendWhatsappMessage::dispatch($message->id)
                ->delay(now()->addMilliseconds($delaySeconds));

            $delaySeconds += $intervalMs;
        }

        $broadcast->update([
            'total' => $total,
            'failed' => $failed,
            'status' => $total > 0 ? 'sending' : 'completed',
            'finished_at' => $total > 0 ? null : now(),
        ]);
    }
}
