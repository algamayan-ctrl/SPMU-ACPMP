<?php

namespace App\Http\Controllers;

use App\Models\Allocation;
use App\Models\CustodyTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $allocations = Allocation::query()
            ->with(['requestItem.inventoryItem', 'requestItem.version.request.borrower.organizationalUnit'])
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_RELEASED'])
            ->orderBy('period_start')
            ->get();
        $custodies = CustodyTransaction::query()
            ->with(['borrower.organizationalUnit', 'lines.requestItem.inventoryItem'])
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_RETURNED', 'OVERDUE', 'EARLY_RETURN', 'INCIDENT_OPEN'])
            ->orderBy('due_at')
            ->get();

        return view('calendar.index', ['allocations' => $allocations, 'custodies' => $custodies, 'workspace' => strtoupper((string) $request->session()->get('active_workspace'))]);
    }
}
