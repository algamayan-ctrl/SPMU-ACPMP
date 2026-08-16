@extends('layouts.app', ['title' => $user->exists ? 'Edit User' : 'Create User'])
@section('content')
@php
    $isSelfProtected = auth()->check() && auth()->id() === $user->id;
    $hasRestrictions = $user->exists && $user->activeRestrictions()->exists();
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">ICTU identity administration</p>
        <h1>{{ $user->exists ? 'Edit institutional account' : 'Register a CSPC account' }}</h1>
        <p>Only verified employees, faculty, and staff may be registered. The selected classification automatically controls borrowing eligibility and portal access.</p>
    </div>
</section>

<section class="content-area narrow">
    <form method="post" action="{{ $user->exists ? route('administration.users.update', $user) : route('administration.users.store') }}" class="card form-grid admin-form-grid">
        @csrf
        @if($user->exists)
            @method('PUT')
        @endif

        <fieldset>
            <legend>Account Identity</legend>
            <div class="form-columns">
                <label>
                    Employee number
                    <input name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" required>
                </label>
                <label>
                    Full name
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" required>
                </label>
                <label>
                    Designation
                    <input name="designation" value="{{ old('designation', $user->designation) }}">
                </label>
                <label>
                    Official CSPC email
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Organizational Assignment</legend>
            <div class="form-columns">
                <label>
                    Office / Department
                    <select name="organizational_unit_id" required>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected(old('organizational_unit_id', $user->organizational_unit_id) == $unit->id)>
                                {{ $unit->unit_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Employment eligibility
                    <select name="employment_type" required>
                        @foreach($employmentTypes as $type)
                            <option value="{{ $type->value }}" @selected(old('employment_type', $user->employment_type?->value) === $type->value)>
                                {{ $type->value }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Classification / Role</legend>
            <div class="form-columns">
                <label>
                    Access classification
                    <select name="access_classification" required @disabled($isSelfProtected)>
                        @foreach($classifications as $classification)
                            <option value="{{ $classification->value }}" @selected(old('access_classification', $user->access_classification?->value ?? 'BORROWER_ONLY') === $classification->value)>
                                {{ $classification->label() }}
                            </option>
                        @endforeach
                    </select>
                    @if($isSelfProtected)
                        <small class="field-note">This field is protected for your own account.</small>
                    @endif
                </label>

                <div class="admin-inline-note">
                    <p><strong>Portal effect:</strong> the selected classification controls the assigned workspace and access rules.</p>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Account Status</legend>
            <div class="form-columns">
                <label>
                    Account status
                    <select name="account_status" required @disabled($isSelfProtected)>
                        @foreach($accountStatuses as $status)
                            @php
                                $accountStatusLabel = match ($status->value) {
                                    'ACTIVE' => 'Active',
                                    'INACTIVE' => 'Inactive',
                                    'SUSPENDED' => 'Suspended',
                                    default => $status->value,
                                };
                            @endphp
                            <option value="{{ $status->value }}" @selected(old('account_status', $user->account_status?->value ?? 'ACTIVE') === $status->value)>
                                {{ $accountStatusLabel }}
                            </option>
                        @endforeach
                    </select>
                    @if($isSelfProtected)
                        <small class="field-note">You cannot change your own active status from this screen.</small>
                    @endif
                </label>

                <div class="admin-inline-note admin-inline-note-warning">
                    <p><strong>Borrowing restriction:</strong> separate from account status and independent of the portal role.</p>
                    @if($hasRestrictions)
                        <x-status-badge status="BORROWING_RESTRICTED" />
                    @else
                        <span class="status-badge status-neutral">Not restricted</span>
                    @endif
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Contact Information</legend>
            <div class="form-columns">
                <label>
                    Mobile number
                    <input name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}">
                </label>
                <div class="admin-inline-note">
                    <p><strong>Contact notes:</strong> email and mobile details are informational and must match official records.</p>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Administrative Actions</legend>
            <div class="form-columns">
                <label>
                    {{ $user->exists ? 'New password (leave blank to retain)' : 'Password' }}
                    <input type="password" name="password" @required(!$user->exists)>
                </label>
                <label>
                    Confirm password
                    <input type="password" name="password_confirmation" @required(!$user->exists)>
                </label>
            </div>
        </fieldset>

        <div class="actions admin-form-actions">
            <button class="button primary ui-pressable">Save institutional account</button>
            <a class="button secondary ui-pressable" href="{{ route('administration.users.index') }}">Cancel</a>
        </div>
    </form>
</section>
@endsection
