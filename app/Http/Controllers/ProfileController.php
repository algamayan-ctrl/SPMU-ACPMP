<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\UserSignature;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()->load('organizationalUnit', 'currentSignature.file')]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'system_notifications' => ['nullable', 'boolean'],
            'email_notifications' => ['nullable', 'boolean'],
            'sms_notifications' => ['nullable', 'boolean'],
        ]);
        $before = $user->only(['full_name', 'designation', 'mobile_no', 'notification_preferences']);
        $user->update([
            'full_name' => $data['full_name'],
            'designation' => $data['designation'] ?? null,
            'mobile_no' => $data['mobile_no'] ?? null,
            'notification_preferences' => [
                'system' => $request->boolean('system_notifications'),
                'email' => $request->boolean('email_notifications'),
                'sms' => $request->boolean('sms_notifications'),
            ],
        ]);
        $audit->record('PROFILE_UPDATED', $user, before: $before, after: $user->only(array_keys($before)));

        return back()->with('status', 'Profile updated.');
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
