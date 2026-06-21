<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
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

    public function show(Request $request, Driver $driver): View
    {
        return view('admin.drivers.show', $this->drivers->showData($driver, $request));
    }
}
