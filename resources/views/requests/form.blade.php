@extends('layouts.app', ['title' => $borrowingRequest->exists ? 'Edit Borrowing Request' : 'Create Borrowing Request'])
@section('content')
@php
    $editing = $borrowingRequest->exists;
    $currentItems = $version->exists ? $version->items->keyBy('inventory_item_id') : collect();
    $supporting = $version->exists ? $version->supportingDocuments->where('is_current', true) : collect();
    $requestLetter = $supporting->firstWhere('document_type', App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER);
    $ptc = $supporting->firstWhere('document_type', App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT);
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Borrowing request</p>
        <h1>{{ $editing ? 'Edit request' : 'Create request' }}</h1>
        <p>Saving a request does not reserve inventory. Reservation happens only after SPMU verifies and approves the submitted request.</p>
    </div>
</section>

<form method="post" action="{{ $editing ? route('requests.update', $borrowingRequest) : route('requests.store') }}" enctype="multipart/form-data" class="content-area form-grid">
    @csrf
    @if($editing) @method('PUT') @endif

    <section class="card form-grid">
        <div class="card-header"><div><p class="eyebrow">1. Request details</p><h2>Event and purpose</h2></div></div>
        <label>Purpose / Event
            <input name="purpose_event" value="{{ old('purpose_event', $version->purpose_event) }}" required maxlength="255">
            @error('purpose_event')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Event Details
            <textarea name="event_details" required>{{ old('event_details', $version->event_details) }}</textarea>
            @error('event_details')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Location
            <input name="location" value="{{ old('location', $version->location) }}" required maxlength="255">
            @error('location')<small class="field-error">{{ $message }}</small>@enderror
        </label>
    </section>

    <section class="card form-grid">
        <div class="card-header"><div><p class="eyebrow">2. Borrowing period</p><h2>Calendar dates only</h2></div></div>
        <p class="meta">Borrowers choose dates only. SPMU assigns the exact pickup time after approval.</p>
        <div class="form-columns">
            <label>Schedule Date
                <input id="schedule_date" type="date" name="schedule_date" value="{{ old('schedule_date', optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')) }}" required>
                @error('schedule_date')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Expected Return Date
                <input id="return_date" type="date" name="return_date" value="{{ old('return_date', optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')) }}" required>
                @error('return_date')<small class="field-error">{{ $message }}</small>@enderror
            </label>
        </div>
    </section>

    <section class="card form-grid">
        <div class="card-header"><div><p class="eyebrow">3. Student activity</p><h2>Supporting activity information</h2></div></div>
        <label class="checkbox">
            <input id="student-activity" type="checkbox" name="represents_student_activity" value="1" @checked(old('represents_student_activity', $version->represents_student_activity))>
            This request represents a student activity / organization
        </label>
        <div id="student-fields" class="form-columns">
            <label>Student Organization
                <input name="student_organization" value="{{ old('student_organization', $version->student_organization) }}">
            </label>
            <label>Program / Department
                <input name="represented_program_department" value="{{ old('represented_program_department', $version->represented_program_department) }}">
            </label>
            <label>Year Level
                <input name="represented_year_level" value="{{ old('represented_year_level', $version->represented_year_level) }}">
            </label>
        </div>
    </section>

    <section class="card form-grid">
        <div class="card-header"><div><p class="eyebrow">4. Inventory</p><h2>Select serviceable available items</h2></div></div>
        <p id="availability-message" class="meta">Availability is informational while drafting and will be rechecked by SPMU before reservation.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Select</th><th>Item</th><th>Available</th><th>Quantity</th><th>Use location</th></tr></thead>
                <tbody>
                @foreach($items as $item)
                    @php
                        $selected = $currentItems->get($item->id);
                    @endphp
                    <tr>
                        <td><input type="checkbox" name="item_ids[]" value="{{ $item->id }}" @checked($selected || in_array($item->id, old('item_ids', [])))></td>
                        <td><strong>{{ $item->unique_description }}</strong><small>{{ $item->unit?->unit_name }}</small></td>
                        <td><span class="availability-value" data-item="{{ $item->id }}">—</span></td>
                        <td><input type="number" step="0.001" min="0" name="quantities[{{ $item->id }}]" value="{{ old('quantities.'.$item->id, $selected?->requested_quantity ?? 0) }}"></td>
                        <td>
                            <select name="locations[{{ $item->id }}]">
                                <option value="ON_CAMPUS" @selected(old('locations.'.$item->id, $selected?->use_location ?? 'ON_CAMPUS') === 'ON_CAMPUS')>On Campus</option>
                                @if($item->off_campus_allowed)
                                    <option value="OFF_CAMPUS" @selected(old('locations.'.$item->id, $selected?->use_location) === 'OFF_CAMPUS')>Off Campus</option>
                                @endif
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @error('items')<small class="field-error">{{ $message }}</small>@enderror
        @error('quantities')<small class="field-error">{{ $message }}</small>@enderror
    </section>

    <section class="card form-grid">
        <div class="card-header"><div><p class="eyebrow">5. Required scanned documents</p><h2>Approved physical documents</h2></div></div>
        <p class="meta">
            Save the draft first to generate the printable Borrowing Request Letter. Print it,
            obtain the required handwritten/wet signatures from the institutional signatories,
            scan the fully accomplished letter, then upload that scan here. The system does not
            apply electronic signatures.
        </p>
        @if($requestLetter)
            <p>Current Borrowing Request Letter: <a href="{{ route('files.show', $requestLetter->file, false) }}" target="_blank" rel="noopener">View uploaded file</a></p>
        @endif
        <label>Fully Signed Borrowing Request Letter
            <input type="file" name="approved_request_letter" accept="application/pdf,image/png,image/jpeg,image/webp">
            <small>{{ $requestLetter ? 'Upload only if replacing the current document.' : 'Required before submission; draft may be saved first.' }}</small>
        </label>
        @if($ptc)
            <p>Current Permission to Conduct Letter: <a href="{{ route('files.show', $ptc->file, false) }}" target="_blank" rel="noopener">View uploaded file</a></p>
        @endif
        <label>Permission to Conduct Letter
            <input type="file" name="permission_to_conduct_letter" accept="application/pdf,image/png,image/jpeg,image/webp">
            <small>Required when the request represents a student activity / organization.</small>
        </label>
        <label>Remarks <textarea name="remarks">{{ old('remarks', $version->remarks) }}</textarea></label>
    </section>

    <div class="form-actions">
        <button class="button primary ui-pressable" type="submit">{{ $editing ? 'Save Draft Changes' : 'Save Draft Request' }}</button>
        <a class="button secondary ui-pressable" href="{{ route('requests.index') }}">Cancel</a>
    </div>
</form>

<script>
(() => {
    const studentToggle = document.getElementById('student-activity');
    const studentFields = document.getElementById('student-fields');
    const toggleStudentFields = () => {
        const enabled = !!studentToggle?.checked;
        studentFields?.querySelectorAll('input').forEach((input) => {
            if (input.name === 'represented_program_department' || input.name === 'represented_year_level') {
                input.required = enabled;
            }
        });
    };
    studentToggle?.addEventListener('change', toggleStudentFields);
    toggleStudentFields();

    let timer;
    const refreshAvailability = () => {
        const from = document.getElementById('schedule_date')?.value;
        const to = document.getElementById('return_date')?.value;
        if (!from || !to) return;
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const message = document.getElementById('availability-message');
            try {
                const response = await fetch(`{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, {headers:{Accept:'application/json'}});
                if (!response.ok) throw new Error();
                const data = await response.json();
                document.querySelectorAll('.availability-value').forEach((node) => {
                    const balance = data[node.dataset.item];
                    if (!balance) return;
                    node.textContent = balance.available;
                    const quantity = document.querySelector(`[name="quantities[${node.dataset.item}]"]`);
                    if (quantity) quantity.max = balance.available;
                });
                message.textContent = 'Availability shown for the complete selected calendar period. Submission still creates no reservation.';
            } catch (error) {
                message.textContent = 'Enter a valid Schedule Date and Return Date to calculate availability.';
            }
        }, 250);
    };
    document.getElementById('schedule_date')?.addEventListener('change', refreshAvailability);
    document.getElementById('return_date')?.addEventListener('change', refreshAvailability);
    refreshAvailability();
})();
</script>
@endsection
