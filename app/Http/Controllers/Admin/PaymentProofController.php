<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Services\Admin\AdminPaymentProofVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentProofController extends Controller
{
    public function __construct(private readonly AdminPaymentProofVerificationService $proofs) {}

    public function approve(Request $request, Order $order, OrderEvidence $evidence): RedirectResponse
    {
        $this->proofs->approve($request->user(), $order, $evidence);

        return redirect()
            ->route('admin.orders.show', ['order' => $order->id, 'focus' => 'payment-proof'])
            ->with('success', 'Bukti QRIS disetujui dan pembayaran ditandai lunas.');
    }

    public function reject(Request $request, Order $order, OrderEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->proofs->reject($request->user(), $order, $evidence, (string) $validated['rejection_reason']);

        return redirect()
            ->route('admin.orders.show', ['order' => $order->id, 'focus' => 'payment-proof'])
            ->with('success', 'Bukti QRIS ditolak. Customer dapat mengirim bukti baru.');
    }
}
