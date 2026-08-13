<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\TemporaryDelegation;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DelegationController extends Controller
{
    public function index(): View
    {
        return view('administration.delegations', [
            'delegations' => TemporaryDelegation::with(['absentHead', 'delegate', 'recorder'])->latest('effective_from')->get(),
            'heads' => User::whereIn('access_classification', [AccessClassification::SpmuHead->value, AccessClassification::GsuHead->value, AccessClassification::VpafHead->value])->orderBy('full_name')->get(),
            'officers' => User::where('account_status', 'ACTIVE')->whereNotIn('access_classification', [AccessClassification::SpmuHead->value, AccessClassification::GsuHead->value, AccessClassification::VpafHead->value])->orderBy('full_name')->get(),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'office_role' => ['required', Rule::in(['SPMU', 'GSU', 'VPAF'])],
            'absent_head_user_id' => ['required', 'exists:users,id'],
            'delegate_user_id' => ['required', 'different:absent_head_user_id', 'exists:users,id'],
            'authority_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after:effective_from'],
        ]);
        $head = User::findOrFail($data['absent_head_user_id']);
        $delegate = User::findOrFail($data['delegate_user_id']);
        $expected = match ($data['office_role']) {
            'SPMU' => AccessClassification::SpmuHead,
            'GSU' => AccessClassification::GsuHead,
            default => AccessClassification::VpafHead,
        };
        if ($head->access_classification !== $expected) {
            throw ValidationException::withMessages(['absent_head_user_id' => 'The selected Head does not match the delegated office.']);
        }
        if ($head->organizational_unit_id !== $delegate->organizational_unit_id) {
            throw ValidationException::withMessages(['delegate_user_id' => 'The delegated officer must belong to the same organizational unit as the Head.']);
        }
        $overlap = TemporaryDelegation::query()->where('office_role', $data['office_role'])->where('status', 'ACTIVE')->whereNull('revoked_at')
            ->where('effective_from', '<=', $data['effective_to'])->where('effective_to', '>=', $data['effective_from'])->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => 'Another active delegation overlaps this office and period.']);
        }
        $delegation = TemporaryDelegation::create($data + ['recorded_by_user_id' => $request->user()->id, 'status' => 'ACTIVE']);
        $audit->record('TEMPORARY_DELEGATION_CREATED', $delegation, reason: $data['reason'], after: $delegation->toArray());

        return back()->with('status', 'Temporary delegated approver recorded. The officer must use their own account and e-signature.');
    }

    public function revoke(Request $request, TemporaryDelegation $delegation, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $delegation->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id, 'revocation_reason' => $data['reason']]);
        $audit->record('TEMPORARY_DELEGATION_REVOKED', $delegation, reason: $data['reason']);

        return back()->with('status', 'Temporary delegation revoked.');
    }
}
