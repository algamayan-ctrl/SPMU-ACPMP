<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\OrganizationalUnit;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** @var list<string> */
    private const BORROWER_DEPARTMENT_NAMES = [
        'College of Health and Sciences',
        'College of Engineering and Architecture',
        'College of Tourism, Hospitality and Business Management',
        'College of Computer Studies',
        'College of Arts and Sciences',
        'College of Technological Developmental Education',
    ];

    public function show(Request $request): View
    {
        $user = $request->user()->load('organizationalUnit');

        return view('profile.show', [
            'user' => $user,
            'borrowerUnits' => $user->access_classification === AccessClassification::BorrowerOnly
                ? OrganizationalUnit::query()
                    ->where('active', true)
                    ->whereIn('unit_name', self::BORROWER_DEPARTMENT_NAMES)
                    ->orderBy('unit_name')
                    ->get()
                : collect(),
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $isBorrower = $user->access_classification === AccessClassification::BorrowerOnly;

        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'system_notifications' => ['nullable', 'boolean'],
            'email_notifications' => ['nullable', 'boolean'],
            'sms_notifications' => ['nullable', 'boolean'],
        ];

        if ($isBorrower) {
            $allowedBorrowerUnitIds = OrganizationalUnit::query()
                ->where('active', true)
                ->whereIn('unit_name', self::BORROWER_DEPARTMENT_NAMES)
                ->pluck('id')
                ->all();

            $rules['employee_no'] = [
                'required',
                'string',
                'max:80',
                Rule::unique('users')->ignore($user->id),
            ];
            $rules['organizational_unit_id'] = ['required', Rule::in($allowedBorrowerUnitIds)];
        }

        $data = $request->validate($rules);

        $updatedFields = ['full_name', 'designation', 'mobile_no', 'notification_preferences'];
        $updates = [
            'full_name' => $data['full_name'],
            'designation' => $data['designation'] ?? null,
            'mobile_no' => $data['mobile_no'] ?? null,
            'notification_preferences' => [
                'system' => $request->boolean('system_notifications'),
                'email' => $request->boolean('email_notifications'),
                'sms' => $request->boolean('sms_notifications'),
            ],
        ];

        if ($isBorrower) {
            $updates['employee_no'] = $data['employee_no'];
            $updates['organizational_unit_id'] = $data['organizational_unit_id'];
            array_push($updatedFields, 'employee_no', 'organizational_unit_id');
        }

        $before = $user->only($updatedFields);
        $user->update($updates);
        $audit->record('PROFILE_UPDATED', $user, before: $before, after: $user->only($updatedFields));

        return back()->with('status', 'Account settings updated.');
    }

}
