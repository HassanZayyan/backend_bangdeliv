<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDriverQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DriverController extends Controller
{
    public function __construct(private readonly AdminDriverQueryService $drivers) {}

    public function index(Request $request): View
    {
        return view('admin.drivers.index', $this->drivers->indexData($request));
    }
}
