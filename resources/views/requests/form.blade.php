@extends('layouts.app', ['title' => $borrowingRequest->exists ? 'Edit Request' : 'Create Request'])
@section('content')
@php
    $selected = $version->exists ? $version->items->keyBy('inventory_item_id') : collect();
    $isReturned = $borrowingRequest->exists && $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;
    $returnRemarks = $isReturned ? $version->approvalSteps->where('decision', 'RETURNED')->pluck('remarks')->filter() : collect();
@endphp

<section class="page-heading request-form-heading">
    <div>
        <p class="eyebrow">Borrowing request</p>
        <h1>{{ $isReturned ? 'Revise your borrowing request' : ($borrowingRequest->exists ? 'Edit request draft' : 'Create a borrowing request') }}</h1>
        <p>Complete the borrowing details, schedule, and item quantities below. Availability applies to the entire selected period.</p>
    </div>
    <div class="step-chip">Draft details</div>
</section>

@if($isReturned)
<section class="content-area">
    <div class="action-panel action-warning" role="status">
        <div><p class="eyebrow">Action required</p><h2>Returned for Revision</h2><p>Update the request using the review remarks below. Saving creates the next request version without removing the previous record.</p></div>
        @if($returnRemarks->isNotEmpty())<ul>@foreach($returnRemarks as $remark)<li>{{ $remark }}</li>@endforeach</ul>@else<p>No specific reviewer remark was recorded. Review the request details carefully before saving.</p>@endif
    </div>
</section>
@endif

<section class="content-area request-form-shell">
<form method="post" action="{{ $borrowingRequest->exists ? route('requests.update', $borrowingRequest) : route('requests.store') }}" class="form-grid" id="request-form">
    @csrf
    @if($borrowingRequest->exists)@method('PUT')@endif

    <section class="card form-section" aria-labelledby="borrowing-details-heading">
        <div class="section-number" aria-hidden="true">01</div>
        <div><h2 id="borrowing-details-heading">Borrowing details</h2><p class="meta">Describe the activity and where the property will be used.</p></div>
        <div class="form-columns full-span">
            <label>Purpose of borrowing<input name="purpose_event" value="{{ old('purpose_event', $version->purpose_event) }}" required>@error('purpose_event')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>Event or activity location<input name="location" value="{{ old('location', $version->location) }}" required>@error('location')<small class="field-error">{{ $message }}</small>@enderror</label>
        </div>
        <label class="full-span">Event or activity details<textarea name="event_details" required>{{ old('event_details', $version->event_details) }}</textarea><small>Include enough information for reviewers to understand the institutional use.</small>@error('event_details')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="full-span">Additional note to reviewers <small>(optional)</small><textarea name="remarks">{{ old('remarks', $version->remarks) }}</textarea>@error('remarks')<small class="field-error">{{ $message }}</small>@enderror</label>
    </section>

    <section class="card form-section" aria-labelledby="schedule-heading">
        <div class="section-number" aria-hidden="true">02</div>
        <div><h2 id="schedule-heading">Borrowing schedule</h2><p class="meta">Dates cannot be extended after approval. Choose the complete use period.</p></div>
        <div class="form-columns full-span">
            <label>Items needed from<input id="needed_from" type="datetime-local" name="needed_from" value="{{ old('needed_from', optional($version->needed_from)->format('Y-m-d\TH:i')) }}" required>@error('needed_from')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>Expected return date<input id="return_due_at" type="datetime-local" name="return_due_at" value="{{ old('return_due_at', optional($version->return_due_at)->format('Y-m-d\TH:i')) }}" required>@error('return_due_at')<small class="field-error">{{ $message }}</small>@enderror</label>
        </div>
    </section>

    <section class="card form-section" aria-labelledby="represented-activity-heading">
        <div class="section-number" aria-hidden="true">03</div>
        <div><h2 id="represented-activity-heading">Represented organization or program</h2><p class="meta">Complete this only when borrowing on behalf of a student activity. You remain the accountable borrower.</p></div>
        <label class="checkbox full-span"><input id="represents_students" type="checkbox" name="represents_student_activity" value="1" @checked(old('represents_student_activity', $version->represents_student_activity))> This request represents a student activity</label>
        <div id="student-fields" class="form-columns full-span">
            <label>Student organization <small>(optional)</small><input name="student_organization" value="{{ old('student_organization', $version->student_organization) }}"></label>
            <label>Program or department<input name="represented_program_department" value="{{ old('represented_program_department', $version->represented_program_department) }}">@error('represented_program_department')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>Year level<input name="represented_year_level" value="{{ old('represented_year_level', $version->represented_year_level) }}">@error('represented_year_level')<small class="field-error">{{ $message }}</small>@enderror</label>
        </div>
    </section>

    <section class="card form-section item-selection-section" aria-labelledby="items-heading">
        <div class="section-number" aria-hidden="true">04</div>
        <div><h2 id="items-heading">Requested items</h2><p class="meta" id="availability-message">Choose valid dates to calculate real-time availability.</p></div>
        @error('item_ids')<p class="field-error full-span">{{ $message }}</p>@enderror
        @error('items')<p class="field-error full-span">{{ $message }}</p>@enderror
        @error('quantities')<p class="field-error full-span">{{ $message }}</p>@enderror
        @error('locations')<p class="field-error full-span">{{ $message }}</p>@enderror
        <div class="table-wrap full-span request-items-table">
            <table>
                <thead><tr><th scope="col">Select</th><th scope="col">Item</th><th scope="col">Unit</th><th scope="col">Available for dates</th><th scope="col">Requested quantity</th><th scope="col">Use location</th></tr></thead>
                <tbody>
                @foreach($items as $item)
                    @php
                        $requestItem = $selected->get($item->id);
                    @endphp
                    <tr>
                        <td data-label="Select"><input type="checkbox" name="item_ids[]" value="{{ $item->id }}" aria-label="Select {{ $item->unique_description }}" @checked(old("quantities.$item->id", $requestItem?->requested_quantity ?? 0) > 0)></td>
                        <td data-label="Item"><strong>{{ $item->unique_description }}</strong>@if($item->specification)<small>{{ $item->specification }}</small>@endif @if($item->laundry_required)<span class="badge">Laundry Form required</span>@endif @if($item->off_campus_allowed)<span class="badge">Off-campus allowed</span>@endif</td>
                        <td data-label="Unit">{{ $item->unit->unit_name }}</td>
                        <td data-label="Available for dates"><strong class="availability-value" data-item="{{ $item->id }}">Select dates</strong><small>Total stock: {{ $item->total_quantity + 0 }}</small></td>
                        <td data-label="Requested quantity"><input class="quantity-input" type="number" step="0.001" min="0" max="{{ $item->total_quantity }}" name="quantities[{{ $item->id }}]" aria-label="Requested quantity for {{ $item->unique_description }}" value="{{ old("quantities.$item->id", $requestItem?->requested_quantity ?? 0) }}"></td>
                        <td data-label="Use location">@if($item->off_campus_allowed)<select name="locations[{{ $item->id }}]" aria-label="Use location for {{ $item->unique_description }}"><option value="ON_CAMPUS" @selected(old("locations.$item->id", $requestItem?->use_location) === 'ON_CAMPUS')>On-campus</option><option value="OFF_CAMPUS" @selected(old("locations.$item->id", $requestItem?->use_location) === 'OFF_CAMPUS')>Off-campus</option></select>@else<input type="hidden" name="locations[{{ $item->id }}]" value="ON_CAMPUS"><span class="locked-value">On-campus only</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card form-section review-section" aria-labelledby="review-heading">
        <div class="section-number" aria-hidden="true">05</div>
        <div><h2 id="review-heading">Review and save your draft</h2><p class="meta">Saving does not submit the request for approval.</p></div>
        <div class="full-span review-note">
            <p>The system will save your request and generate an official preview. Review that document on the next screen before using your profile e-signature to certify and submit it to SPMU.</p>
            <ul><li>Availability will be checked again.</li><li>Only selected items with a quantity greater than zero are saved.</li><li>Submission creates an immutable certification and signature snapshot.</li></ul>
        </div>
    </section>

    <div class="actions sticky-actions"><button class="button primary ui-pressable">Save draft and generate preview</button><a class="button secondary" href="{{ route('requests.index') }}">Cancel</a></div>
</form>
</section>

<script>
const studentToggle=document.getElementById('represents_students'); const studentFields=document.getElementById('student-fields');
function toggleStudentFields(){studentFields.classList.toggle('is-hidden',!studentToggle.checked); studentFields.querySelectorAll('input').forEach((el)=>{if(el.name!=='student_organization')el.required=studentToggle.checked;});}
studentToggle.addEventListener('change',toggleStudentFields); toggleStudentFields();
let availabilityTimer;
async function refreshAvailability(){const from=document.getElementById('needed_from').value;const to=document.getElementById('return_due_at').value;if(!from||!to)return;clearTimeout(availabilityTimer);availabilityTimer=setTimeout(async()=>{const message=document.getElementById('availability-message');message.textContent='Checking real-time availability…';try{const response=await fetch(`{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error();const data=await response.json();document.querySelectorAll('.availability-value').forEach((node)=>{const balance=data[node.dataset.item];if(!balance)return;node.textContent=balance.available;const quantity=document.querySelector(`[name="quantities[${node.dataset.item}]"]`);quantity.max=balance.available;});message.textContent='Availability is calculated for the complete selected period and will be rechecked at submission and final approval.';}catch(e){message.textContent='Enter a valid borrowing period to calculate availability.';}},250);}
document.getElementById('needed_from').addEventListener('change',refreshAvailability);document.getElementById('return_due_at').addEventListener('change',refreshAvailability);refreshAvailability();
</script>
@endsection
