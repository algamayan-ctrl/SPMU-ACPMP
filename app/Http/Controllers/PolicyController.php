<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\SanctionRule;
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
            'sanctionRules' => SanctionRule::query()->orderBy('offense_no')->get(),
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

    public function storeSanctionRule(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorizeHead($request);

        $data = $request->validate([
            'offense_no' => ['required', 'integer', 'min:1', 'max:99'],
            'sanction_code' => ['required', 'string', 'max:50'],
            'sanction_label' => ['required', 'string', 'max:255'],
            'duration_mode' => ['required', Rule::in(['NONE', 'END_OF_PERIOD', 'MANUAL'])],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $rule = SanctionRule::query()->updateOrCreate(
            [
                'offense_no' => $data['offense_no'],
                'sanction_code' => strtoupper($data['sanction_code']),
            ],
            [
                'sanction_label' => $data['sanction_label'],
                'duration_mode' => $data['duration_mode'],
                'status' => $data['status'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'configured_by_user_id' => $request->user()->id,
            ]
        );

        $audit->record('SANCTION_RULE_CONFIGURED', $rule, after: $rule->toArray());

        return back()->with('status', 'Sanction rule saved. Historical sanctions were not changed.');
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

    public function updateSanctionRule(
        Request $request,
        SanctionRule $rule,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'offense_no' => ['required', 'integer', 'min:1', 'max:99'],
            'sanction_code' => ['required', 'string', 'max:50'],
            'sanction_label' => ['required', 'string', 'max:255'],
            'duration_mode' => ['required', Rule::in(['NONE', 'END_OF_PERIOD', 'MANUAL'])],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $before = $rule->toArray();
        $rule->update([
            'offense_no' => $data['offense_no'],
            'sanction_code' => strtoupper($data['sanction_code']),
            'sanction_label' => $data['sanction_label'],
            'duration_mode' => $data['duration_mode'],
            'status' => $data['status'],
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'configured_by_user_id' => $request->user()->id,
        ]);

        $audit->record(
            'SANCTION_RULE_UPDATED',
            $rule,
            before: $before,
            after: $rule->fresh()->toArray()
        );

        return back()->with('status', 'Sanction rule updated. Historical sanctions remain unchanged.');
    }

    private function authorizeHead(Request $request): void
    {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may configure academic periods and sanctions.'
        );
    }
}
