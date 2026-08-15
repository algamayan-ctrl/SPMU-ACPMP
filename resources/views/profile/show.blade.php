@extends('layouts.app', ['title' => 'Account Settings'])
@section('content')
@php($isBorrower = $user->access_classification === App\Enums\AccessClassification::BorrowerOnly)
<section class="page-heading">
    <div>
        <p class="eyebrow">Personal account</p>
        <h1>Account Settings</h1>
    </div>
</section>

<section class="content-grid two profile-layout">
    <form method="post" action="{{ route('profile.update') }}" class="card form-grid account-settings-form">
        @csrf
        @method('PUT')

        <section class="account-settings-section" aria-labelledby="account-details-heading">
            <div class="section-heading">
                <div><p class="eyebrow">Identity</p><h2 id="account-details-heading">Account Details</h2></div>
            </div>

            @if($isBorrower)
                @if($missingBorrowerDepartments !== [])
                    <p class="field-help">Borrower department configuration is incomplete. Missing: {{ implode(', ', $missingBorrowerDepartments) }}.</p>
                @endif
                <div class="form-columns">
                    <label>Borrower Number
                        <input name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" required maxlength="80" autocomplete="off">
                        @error('employee_no')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label>Office / Department
                        <select name="organizational_unit_id" required>
                            <option value="">Select office or department</option>
                            @foreach($borrowerUnits as $unit)
                                <option value="{{ $unit->id }}" @selected((string) old('organizational_unit_id', $user->organizational_unit_id) === (string) $unit->id)>{{ $unit->unit_name }}</option>
                            @endforeach
                        </select>
                        @error('organizational_unit_id')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            @else
                <div class="profile-readonly-grid">
                    <div><span>Employee Number</span><strong>{{ $user->employee_no }}</strong></div>
                    <div><span>Office / Department</span><strong>{{ $user->organizationalUnit?->unit_name ?: 'Not recorded' }}</strong></div>
                </div>
                <p class="field-help">Institutional identifiers and organizational assignments are maintained by ICTU because they determine portal authority, approval routing, and delegation eligibility.</p>
            @endif

            <div class="form-columns">
                <label>Full name
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" required autocomplete="name">
                    @error('full_name')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label>Designation
                    <input name="designation" value="{{ old('designation', $user->designation) }}" autocomplete="organization-title">
                    @error('designation')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="account-settings-section" aria-labelledby="contact-information-heading">
            <div class="section-heading"><div><p class="eyebrow">Communication</p><h2 id="contact-information-heading">Contact Information</h2></div></div>
            <div class="profile-readonly-grid single-row">
                <div class="full-span"><span>Official email</span><strong>{{ $user->email }}</strong></div>
            </div>
            <label>Contact number
                <input name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}" maxlength="30" autocomplete="tel">
                @error('mobile_no')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <fieldset>
                <legend>Notification preferences</legend>
                <p class="meta">Choose how the system may send account and transaction updates.</p>
                <label class="checkbox"><input type="checkbox" name="system_notifications" value="1" @checked(data_get($user->notification_preferences, 'system', true))> In-system notifications</label>
                <label class="checkbox"><input type="checkbox" name="email_notifications" value="1" @checked(data_get($user->notification_preferences, 'email', true))> Email notifications</label>
                <label class="checkbox"><input type="checkbox" name="sms_notifications" value="1" @checked(data_get($user->notification_preferences, 'sms', false))> SMS notifications</label>
            </fieldset>
        </section>

        <div class="form-actions"><button class="button primary ui-pressable" type="submit">Save Changes</button></div>
    </form>

    <div class="profile-side-column">
        <article class="card appearance-settings-card" aria-labelledby="appearance-heading">
            <div class="card-header">
                <div><p class="eyebrow">Display preference</p><h2 id="appearance-heading">Appearance</h2></div>
            </div>
            <label for="appearance-select">Theme
                <select id="appearance-select" data-appearance-select>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                    <option value="system">Default</option>
                </select>
            </label>
            <p class="meta" data-appearance-status aria-live="polite">Default follows this device’s light or dark preference.</p>
        </article>

        <article class="card signature-card" aria-labelledby="signature-heading">
        <div class="card-header">
            <div><p class="eyebrow">Authenticated signing</p><h2 id="signature-heading">E-Signature</h2></div>
            <x-status-badge :status="$user->currentSignature ? 'ACTIVE' : 'MISSING'" :label="$user->currentSignature ? 'Signature ready' : 'Signature required'" />
        </div>
        <p>Your active e-signature is used only for future signing actions. Controlled records that were already signed retain their original immutable signature snapshots.</p>
        @if($user->currentSignature)
            <div class="signature-preview"><img src="{{ route('files.show', $user->currentSignature->file) }}" alt="Current e-signature preview"></div>
            <p class="meta">Current e-signature active since {{ $user->currentSignature->effective_from->format('d M Y, g:i A') }}</p>
        @else
            <div class="empty-state"><strong>No e-signature uploaded.</strong><span>Signing actions remain unavailable until you add one.</span></div>
        @endif
        <div class="callout account-information-callout">
            <x-icon name="information" />
            <div><strong>Before uploading</strong><p>Use a clear PNG, JPG, or WebP image of your signature. Replacing it affects future actions only.</p></div>
        </div>
        <form method="post" action="{{ route('profile.signature') }}" enctype="multipart/form-data" class="form-grid">
            @csrf
            <label>{{ $user->currentSignature ? 'Upload new signature' : 'Choose signature image' }}
                <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" required>
                @error('signature')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <button class="button primary ui-pressable" type="submit">{{ $user->currentSignature ? 'Replace E-Signature' : 'Upload E-Signature' }}</button>
        </form>
        </article>
    </div>
</section>
@endsection
