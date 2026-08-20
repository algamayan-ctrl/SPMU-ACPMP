<?php

namespace App\Http\Controllers;

use App\Models\BorrowerViolation;
use App\Models\Sanction;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SanctionController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));

        $violations = BorrowerViolation::query()
            ->with(['borrower', 'custody.request', 'academicPeriod', 'sanction'])
            ->latest('detected_at');

        $sanctions = Sanction::query()
            ->with(['borrower', 'academicPeriod', 'violation'])
            ->latest('confirmed_at');

        if ($workspace === 'BORROWER') {
            $violations->where('borrower_user_id', $request->user()->id);
            $sanctions->where('borrower_user_id', $request->user()->id);
        }

        return view('sanctions.index', [
            'violations' => $violations->get(),
            'sanctions' => $sanctions->get(),
        ]);
    }

    public function review(
        Request $request,
        BorrowerViolation $violation,
        PolicyService $policy
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:CONFIRMED,DISMISSED'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $sanction = $policy->reviewViolation(
            $violation,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null
        );

        if ($data['decision'] === 'DISMISSED') {
            return back()->with('status', 'Violation dismissed with an audit record.');
        }

        return back()->with(
            'status',
            $sanction
                ? "Violation confirmed. Sanction: {$sanction->sanction_label}."
                : 'Violation confirmed. No active sanction rule matched this offense; SPMU may configure one separately.'
        );
    }
}
