<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private readonly AdminOrderQueryService $orders) {}

    public function index(Request $request): View
    {
        return view('admin.orders.index', $this->orders->indexData($request));
    }

    public function show(Request $request, Order $order): View
    {
        return view('admin.orders.show', $this->orders->detailData($order, $request));
    }
}
