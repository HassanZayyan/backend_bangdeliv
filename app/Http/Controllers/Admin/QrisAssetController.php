<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payment\QrisAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class QrisAssetController extends Controller
{
    public function __invoke(Request $request, QrisAssetService $qrisAssetService): RedirectResponse
    {
        $validated = $request->validate([
            'qris_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'qris_image.required' => 'Pilih gambar QRIS terlebih dahulu.',
            'qris_image.image' => 'File QRIS harus berupa gambar.',
            'qris_image.mimes' => 'Format QRIS harus JPG, PNG, atau WebP.',
            'qris_image.max' => 'Ukuran QRIS maksimal 5 MB.',
        ]);

        $file = $validated['qris_image'] ?? null;
        if ($file instanceof UploadedFile) {
            $qrisAssetService->replace($file);
        }

        return redirect()
            ->route('admin.settings')
            ->with('success', 'QRIS BangDeliv berhasil diperbarui.');
    }
}
