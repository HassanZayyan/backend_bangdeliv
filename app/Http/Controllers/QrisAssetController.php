<?php

namespace App\Http\Controllers;

use App\Services\Payment\QrisAssetService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QrisAssetController extends Controller
{
    public function __invoke(QrisAssetService $qrisAssetService): BinaryFileResponse|StreamedResponse
    {
        return $qrisAssetService->response();
    }
}
