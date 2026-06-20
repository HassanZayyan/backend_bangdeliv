<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(private readonly AdminNotificationService $notifications) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->notifications->summary(),
        ]);
    }
}
