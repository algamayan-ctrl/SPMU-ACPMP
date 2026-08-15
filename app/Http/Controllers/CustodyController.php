<?php

namespace App\Http\Controllers;

use App\Models\CustodyTransaction;
use App\Services\CustodyService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustodyController extends Controller
{
    public function index(Request $request): View
    {
        $query = CustodyTransaction::with(['borrower', 'request', 'lines.requestItem.inventoryItem'])->latest();
        if (strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER') {
            $query->where('borrower_user_id', $request->user()->id);
        }

        return view('custody.index', ['custodies' => $query->get()]);
    }

    public function show(Request $request, CustodyTransaction $custody): View
    {
        $this->authorizeCustody($request, $custody);
        $custody->load(['borrower', 'request.currentVersion', 'lines.allocation', 'lines.requestItem.inventoryItem.unit', 'returns.lines.laundryRecord', 'gatePass', 'earlyReturnRequests.lines']);

        return view('custody.show', [
            'custody' => $custody,
            'documents' => $custody->request->currentVersion->documents()->where(function ($query) use ($custody) {
                $query->where('document_type', 'APPROVED_REQUEST_LETTER')
                    ->orWhere(fn ($query) => $query->where('subject_type', CustodyTransaction::class)->where('subject_id', $custody->id));
            })->with('evidence')->latest()->get(),
        ]);
    }

    public function quantities(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate(['quantities' => ['required', 'array'], 'reasons' => ['nullable', 'array']]);
        $service->updateReceiptQuantities($custody, $request->user(), $data['quantities'], $data['reasons'] ?? []);

        return back()->with('status', 'Quantity to receive saved. SPMU must verify any reduction before acknowledgement.');
    }

    public function prepare(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $service->prepare($custody, $request->user());

        return back()->with('status', 'Prepared quantities verified. Borrower acknowledgement is now available.');
    }

    public function acknowledge(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $service->acknowledge($custody, $request->user());

        return back()->with('status', 'Borrower acknowledgement and e-signature snapshot recorded.');
    }

    public function release(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $service->release($custody, $request->user());

        return back()->with('status', 'Physical release completed. Actual quantities are now Borrowed.');
    }

    public function receiveReturn(Request $request, CustodyTransaction $custody, CustodyService $service, ProtectedFileService $files): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['required', 'array'],
            'conditions.*' => ['required', Rule::in(['FINE', 'DAMAGED', 'DESTROYED', 'MISSING', 'LOST', 'STOLEN'])],
            'police_blotter_references' => ['nullable', 'array'],
            'police_blotter_references.*' => ['nullable', 'string', 'max:255'],
            'evidence_files' => ['nullable', 'array'],
            'evidence_files.*' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'early_return' => ['nullable', 'boolean'],
        ]);
        $evidenceFileIds = [];
        foreach ($request->file('evidence_files', []) as $lineId => $upload) {
            if ($upload) {
                $evidenceFileIds[$lineId] = $files->storeUpload($upload, 'incident-evidence', 'INCIDENT_EVIDENCE')->id;
            }
        }
        $service->receiveReturn(
            $custody,
            $request->user(),
            $data['quantities'],
            $data['conditions'],
            $data['remarks'] ?? null,
            $request->boolean('early_return'),
            $data['police_blotter_references'] ?? [],
            $evidenceFileIds,
        );

        return back()->with('status', 'Return counted and inspected. Fine items, laundry, and incidents were routed to their correct inventory states.');
    }

    public function requestEarlyReturn(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'proposed_return_at' => ['required', 'date', 'after_or_equal:now'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->requestEarlyReturn($custody, $request->user(), $data['quantities'], $data['proposed_return_at'], $data['reason'] ?? null);

        return back()->with('status', 'Early Return notice sent to SPMU. Inventory will change only after physical inspection.');
    }

    private function authorizeCustody(Request $request, CustodyTransaction $custody): void
    {
        $user = $request->user();
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        abort_unless(($workspace === 'BORROWER' && $custody->borrower_user_id === $user->id) || $workspace === 'SPMU', 403);
    }
}
