@extends('layouts.app', ['title' => 'Account Settings'])
@section('content')
@php
    $isBorrower = $user->access_classification === App\Enums\AccessClassification::BorrowerOnly;
@endphp
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
                <div><p class="eyebrow">Document policy</p><h2 id="signature-heading">Physical Signatures</h2></div>
                <x-status-badge status="ACTIVE" label="Wet signatures" />
            </div>
            <p>Borrowing Request Letters, Borrower Slips, Gate Passes, Laundry Forms, and other required operational documents use handwritten/wet signatures on printed copies. No e-signature upload is required for the active borrowing workflow.</p>
            <div class="callout account-information-callout">
                <x-icon name="information" />
                <div><strong>Document evidence</strong><p>Where required, the fully accomplished physical document is scanned and uploaded to the related transaction for SPMU verification and audit history.</p></div>
            </div>
        </article>
    </div>
</section>
@endsection
