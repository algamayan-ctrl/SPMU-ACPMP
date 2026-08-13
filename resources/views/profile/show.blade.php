@extends('layouts.app', ['title' => 'My Profile'])
@section('content')
<section class="page-heading"><div><p class="eyebrow">Identity and authenticated signing</p><h1>My profile</h1><p>Maintain current contact information and the e-signature used for future immutable snapshots.</p></div></section>
<section class="content-grid two">
    <form method="post" action="{{ route('profile.update') }}" class="card form-grid">@csrf @method('PUT')
        <h2>Account details</h2>
        <label>Employee number<input value="{{ $user->employee_no }}" disabled></label>
        <label>Full name<input name="full_name" value="{{ old('full_name', $user->full_name) }}" required></label>
        <label>Designation<input name="designation" value="{{ old('designation', $user->designation) }}"></label>
        <label>Office/Department<input value="{{ $user->organizationalUnit?->unit_name }}" disabled></label>
        <label>Official email<input value="{{ $user->email }}" disabled></label>
        <label>Mobile number<input name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}"></label>
        <fieldset><legend>Notification preferences</legend>
            <label class="checkbox"><input type="checkbox" name="system_notifications" value="1" @checked(data_get($user->notification_preferences, 'system', true))> In-system</label>
            <label class="checkbox"><input type="checkbox" name="email_notifications" value="1" @checked(data_get($user->notification_preferences, 'email', true))> Email</label>
            <label class="checkbox"><input type="checkbox" name="sms_notifications" value="1" @checked(data_get($user->notification_preferences, 'sms', false))> SMS</label>
        </fieldset>
        <button class="button primary" type="submit">Save profile</button>
    </form>
    <div class="card">
        <h2>Profile e-signature</h2><p>PNG/JPG/WebP only. Replacing it affects future actions only; earlier signed records keep their snapshot.</p>
        @if($user->currentSignature)
            <div class="signature-preview"><img src="{{ route('files.show', $user->currentSignature->file) }}" alt="Current e-signature"></div>
            <p class="meta">Effective since {{ $user->currentSignature->effective_from->format('M j, Y g:i A') }}</p>
        @else
            <div class="empty-state">No e-signature uploaded. Signing and approval actions are blocked.</div>
        @endif
        <form method="post" action="{{ route('profile.signature') }}" enctype="multipart/form-data" class="form-grid">@csrf<label>Choose signature image<input type="file" name="signature" accept="image/png,image/jpeg,image/webp" required></label><button class="button primary" type="submit">Upload e-signature</button></form>
    </div>
</section>
@endsection
