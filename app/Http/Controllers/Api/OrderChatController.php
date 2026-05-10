<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\OrderChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderChatController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderChatService $orderChatService) {}

    public function index(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $payload = $this->orderChatService->listMessages(
                $request->user(),
                $orderId,
                $validated,
            );

            return $this->success($payload, 'Pesan chat order berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function unread(Request $request, int $orderId): JsonResponse
    {
        try {
            $payload = $this->orderChatService->unreadSummary(
                $request->user(),
                $orderId,
            );

            return $this->success($payload, 'Jumlah pesan belum dibaca berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function markRead(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $payload = $this->orderChatService->markRead(
                $request->user(),
                $orderId,
                (int) $validated['message_id'],
            );

            return $this->success($payload, 'Pesan chat order ditandai sudah dibaca.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function store(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'client_message_id' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $payload = $this->orderChatService->sendMessage(
                $request->user(),
                $orderId,
                $validated,
            );

            return $this->success($payload, 'Pesan chat order berhasil dikirim.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }
}
