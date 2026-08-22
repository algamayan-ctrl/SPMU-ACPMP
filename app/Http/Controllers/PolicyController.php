<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeHead($request);

        return view('administration.policies', [
            'academicPeriods' => AcademicPeriod::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function storeAcademicPeriod(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorizeHead($request);

        $data = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'term_code' => ['required', Rule::in(['FIRST_SEMESTER', 'SECOND_SEMESTER', 'SUMMER_MIDYEAR'])],
            'term_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['UPCOMING', 'ACTIVE', 'COMPLETED'])],
        ]);

        if ($data['status'] === 'ACTIVE') {
            AcademicPeriod::query()
                ->where('status', 'ACTIVE')
                ->update(['status' => 'COMPLETED']);
        }

        $period = AcademicPeriod::query()->updateOrCreate(
            [
                'academic_year' => $data['academic_year'],
                'term_code' => $data['term_code'],
            ],
            [
                'term_name' => $data['term_name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => $data['status'],
                'configured_by_user_id' => $request->user()->id,
            ]
        );

        $audit->record('ACADEMIC_PERIOD_CONFIGURED', $period, after: $period->toArray());

        return back()->with('status', 'Academic period configuration saved.');
    }

    public function updateAcademicPeriod(
        Request $request,
        AcademicPeriod $period,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'term_code' => ['required', Rule::in(['FIRST_SEMESTER', 'SECOND_SEMESTER', 'SUMMER_MIDYEAR'])],
            'term_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['UPCOMING', 'ACTIVE', 'COMPLETED'])],
        ]);

        if ($data['status'] === 'ACTIVE') {
            AcademicPeriod::query()
                ->whereKeyNot($period->id)
                ->where('status', 'ACTIVE')
                ->update(['status' => 'COMPLETED']);
        }

        $before = $period->toArray();
        $period->update([
            ...$data,
            'configured_by_user_id' => $request->user()->id,
        ]);

        $audit->record(
            'ACADEMIC_PERIOD_UPDATED',
            $period,
            before: $before,
            after: $period->fresh()->toArray()
        );

        return back()->with('status', 'Academic period updated.');
    }

    private function authorizeHead(Request $request): void
    {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may configure academic periods.'
        );
    }
}
