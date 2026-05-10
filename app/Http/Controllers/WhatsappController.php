<?php

namespace App\Http\Controllers;

use App\Helper\PhoneNormalizer;
use App\Jobs\SendWhatsappBroadcast;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappSetting;
use App\Services\RecipientResolver;
use App\Services\UltraMsgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WhatsappController extends Controller
{
    public function getSettings(): JsonResponse
    {
        $setting = WhatsappSetting::current();

        return response()->json([
            'status' => 'success',
            'data' => [
                'instance_id' => $setting->instance_id,
                'has_token' => (bool) $setting->token,
                'status' => $setting->status,
                'connected_at' => $setting->connected_at,
            ],
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id' => 'required|string|max:64',
            'token' => 'required|string|max:128',
        ]);

        $setting = WhatsappSetting::current();
        $setting->update([
            'instance_id' => $data['instance_id'],
            'token' => $data['token'],
            'status' => null,
            'qr' => null,
            'connected_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Saved.',
        ]);
    }

    public function getQr(): JsonResponse
    {
        try {
            $setting = WhatsappSetting::current();
            $service = new UltraMsgService($setting);
            $qr = $service->getQr();
            $status = $service->getStatus();

            $accountStatus = $status['accountStatus']['status'] ?? null;
            $setting->update([
                'qr' => $qr['qrCode'] ?? null,
                'qr_fetched_at' => now(),
                'status' => $accountStatus,
                'connected_at' => $accountStatus === 'authenticated' ? now() : $setting->connected_at,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'qr' => $qr['qrCode'] ?? null,
                    'account_status' => $accountStatus,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function getStatus(): JsonResponse
    {
        try {
            $setting = WhatsappSetting::current();
            $service = new UltraMsgService($setting);
            $status = $service->getStatus();

            $accountStatus = $status['accountStatus']['status'] ?? null;
            $setting->update([
                'status' => $accountStatus,
                'connected_at' => $accountStatus === 'authenticated' ? ($setting->connected_at ?? now()) : $setting->connected_at,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'account_status' => $accountStatus,
                    'raw' => $status,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function previewRecipients(Request $request, RecipientResolver $resolver): JsonResponse
    {
        $filter = $this->validateFilter($request);

        $recipients = $resolver->resolve($filter);
        $valid = $recipients->filter(fn ($r) => $r['phone_normalized'] !== null);
        $invalid = $recipients->count() - $valid->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total' => $recipients->count(),
                'valid' => $valid->count(),
                'invalid' => $invalid,
                'sample' => $recipients->take(10)->values(),
            ],
        ]);
    }

    public function createBroadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:4096',
            'media_type' => 'required|in:text,image,video,link',
            'media' => 'nullable|file|max:51200',
            'media_url' => 'nullable|string|max:2048',
            'link_url' => 'nullable|url|max:2048',
            'recipient_filter' => 'required|array',
        ]);

        $filter = $this->validateFilter($request, $data['recipient_filter']);

        $mediaUrl = $data['media_url'] ?? null;
        if ($request->hasFile('media') && in_array($data['media_type'], ['image', 'video'])) {
            $disk = config('ultramsg.media_disk', 'public');
            $path = $request->file('media')->store('whatsapp', $disk);
            $mediaUrl = asset('storage/' . $path);
        }

        if ($data['media_type'] === 'image' || $data['media_type'] === 'video') {
            if (!$mediaUrl) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Media file or media_url is required for image/video.',
                ], 422);
            }
        }

        if ($data['media_type'] === 'link' && empty($data['link_url'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'link_url is required for link.',
            ], 422);
        }

        $broadcast = WhatsappBroadcast::create([
            'created_by' => $request->user()?->id,
            'body' => $data['body'] ?? null,
            'media_type' => $data['media_type'],
            'media_url' => $mediaUrl,
            'link_url' => $data['link_url'] ?? null,
            'recipient_filter' => $filter,
            'status' => 'pending',
        ]);

        SendWhatsappBroadcast::dispatch($broadcast->id);

        return response()->json([
            'status' => 'success',
            'data' => $broadcast->fresh(),
        ], 201);
    }

    public function listBroadcasts(): JsonResponse
    {
        $broadcasts = WhatsappBroadcast::with('creator:id,name')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $broadcasts,
        ]);
    }

    public function showBroadcast(WhatsappBroadcast $broadcast): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $broadcast->load('creator:id,name'),
        ]);
    }

    public function broadcastMessages(WhatsappBroadcast $broadcast, Request $request): JsonResponse
    {
        $query = $broadcast->messages();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderByDesc('id')->paginate(50),
        ]);
    }

    private function validateFilter(Request $request, ?array $filter = null): array
    {
        $filter ??= $request->input('recipient_filter', []);
        $mode = $filter['mode'] ?? null;

        if (!in_array($mode, ['all_active', 'points_threshold', 'last_transaction', 'manual_ids', 'uploaded_phones'])) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Invalid recipient_filter.mode',
            ], 422));
        }

        if ($mode === 'uploaded_phones') {
            $filter['phones'] = array_values(array_filter(array_map('trim', $filter['phones'] ?? [])));
        }

        return $filter;
    }
}
