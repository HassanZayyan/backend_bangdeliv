<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminCustomerQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private readonly AdminCustomerQueryService $customers) {}

    public function index(Request $request): View
    {
        return view('admin.customers.index', $this->customers->indexData($request));
    }

    public function toggleBlacklist(Request $request, User $customer): RedirectResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $data = $request->validate([
            'is_blacklisted' => ['required', 'boolean'],
        ]);

        $customer->forceFill([
            'is_blacklisted' => (bool) $data['is_blacklisted'],
        ])->save();

        $message = (bool) $data['is_blacklisted']
            ? 'Pelanggan berhasil dimasukkan ke blacklist.'
            : 'Pelanggan berhasil dikeluarkan dari blacklist.';

        return redirect()
            ->route('admin.customers.index', $request->only(['status', 'q']))
            ->with('success', $message);
    }
}
