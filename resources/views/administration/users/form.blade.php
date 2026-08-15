@extends('layouts.app', ['title' => $user->exists ? 'Edit User' : 'Create User'])
@section('content')
<section class="page-heading"><div><p class="eyebrow">ICTU identity administration</p><h1>{{ $user->exists ? 'Edit institutional account' : 'Register a CSPC account' }}</h1><p>Only verified employees, faculty, and staff may be registered. The selected classification automatically controls borrowing eligibility and portal access.</p></div></section>
<section class="content-area narrow"><form method="post" action="{{ $user->exists ? route('administration.users.update',$user) : route('administration.users.store') }}" class="card form-grid">@csrf @if($user->exists)@method('PUT')@endif
<div class="form-columns">
<label>Employee number<input name="employee_no" value="{{ old('employee_no',$user->employee_no) }}" required></label>
<label>Full name<input name="full_name" value="{{ old('full_name',$user->full_name) }}" required></label>
<label>Designation<input name="designation" value="{{ old('designation',$user->designation) }}"></label>
<label>Official CSPC email<input type="email" name="email" value="{{ old('email',$user->email) }}" required></label>
<label>Mobile number<input name="mobile_no" value="{{ old('mobile_no',$user->mobile_no) }}"></label>
<label>Office/Department<select name="organizational_unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('organizational_unit_id',$user->organizational_unit_id)==$unit->id)>{{ $unit->unit_name }}</option>@endforeach</select></label>
<label>Employment eligibility<select name="employment_type" required>@foreach($employmentTypes as $type)<option value="{{ $type->value }}" @selected(old('employment_type',$user->employment_type?->value)===$type->value)>{{ $type->value }}</option>@endforeach</select></label>
<label>Access classification<select name="access_classification" required>@foreach($classifications as $classification)<option value="{{ $classification->value }}" @selected(old('access_classification',$user->access_classification?->value ?? 'BORROWER_ONLY')===$classification->value)>{{ $classification->label() }}</option>@endforeach</select><small>Each classification has one portal. Only the Borrower classification may create borrowing requests.</small></label>
<label>Account status<select name="account_status" required>@foreach($accountStatuses as $status)<option value="{{ $status->value }}" @selected(old('account_status',$user->account_status?->value ?? 'ACTIVE')===$status->value)>{{ $status->value }}</option>@endforeach</select></label>
<label>{{ $user->exists ? 'New password (leave blank to retain)' : 'Password' }}<input type="password" name="password" @required(!$user->exists)></label>
<label>Confirm password<input type="password" name="password_confirmation" @required(!$user->exists)></label>
</div><div class="actions"><button class="button primary">Save institutional account</button><a class="button secondary" href="{{ route('administration.users.index') }}">Cancel</a></div></form></section>
@endsection
