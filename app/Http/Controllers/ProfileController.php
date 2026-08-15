<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\OrganizationalUnit;
use App\Models\SystemSetting;
use App\Models\UserSignature;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load('organizationalUnit', 'currentSignature.file');

        return view('profile.show', [
            'user' => $user,
            'borrowerUnits' => $user->access_classification === AccessClassification::BorrowerOnly
                ? OrganizationalUnit::query()
                    ->where('active', true)
                    ->whereNotIn('unit_code', ['SPMU', 'GSU', 'VPAF', 'ICTU'])
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
            $rules['employee_no'] = ['required', 'string', 'max:80', Rule::unique('users')->ignore($user->id)];
            $rules['organizational_unit_id'] = [
                'required',
                Rule::exists('organizational_units', 'id')->where(
                    fn ($query) => $query
                        ->where('active', true)
                        ->whereNotIn('unit_code', ['SPMU', 'GSU', 'VPAF', 'ICTU']),
                ),
            ];
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

    public function signature(Request $request, ProtectedFileService $files, AuditService $audit): RedirectResponse
    {
        $maxKb = ((int) SystemSetting::value('max_upload_mb', 5)) * 1024;
        $data = $request->validate(['signature' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.$maxKb]]);
        $user = $request->user();

        $user->currentSignature()->update(['status' => 'REPLACED', 'effective_to' => now()]);
        $file = $files->storeUpload($data['signature'], 'profile-signatures', 'PROFILE_SIGNATURE');
        $signature = UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);
        $audit->record('PROFILE_SIGNATURE_REPLACED', $signature, after: ['sha256' => $file->sha256]);

        return back()->with('status', 'E-signature uploaded. Future signing actions will use this version; older snapshots remain unchanged.');
    }
}
