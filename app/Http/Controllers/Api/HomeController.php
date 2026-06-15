<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Home\HomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly HomeService $homeService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit_merchants' => ['nullable', 'integer', 'min:1', 'max:20'],
            'limit_menus' => ['nullable', 'integer', 'min:1', 'max:20'],
            'limit_categories' => ['nullable', 'integer', 'min:1', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return $this->success(
            $this->homeService->payload($validated),
            'Data beranda berhasil diambil.'
        );
    }
}
