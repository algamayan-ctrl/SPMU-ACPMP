<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\SystemSetting;
use App\Models\TechnicalOperation;
use App\Models\User;
use Illuminate\View\View;

class AdministrationController extends Controller
{
    public function index(): View
    {
        return view('administration.index', [
            'userCount' => User::count(),
            'activeUserCount' => User::where('account_status', 'ACTIVE')->count(),
            'openSettings' => SystemSetting::whereNull('value_json')->count(),
            'recentAudits' => AuditEvent::with('actor')->latest('occurred_at')->limit(10)->get(),
            'technicalOperations' => TechnicalOperation::latest('started_at')->limit(5)->get(),
        ]);
    }
}
