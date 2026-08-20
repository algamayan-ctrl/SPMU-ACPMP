@extends('layouts.app', ['title' => $borrowingRequest->exists ? 'Edit Request' : 'Create Request'])

@section('content')

@php
    $selected = $version->exists
        ? $version->items->keyBy('inventory_item_id')
        : collect();

    $isReturned = $borrowingRequest->exists
        && $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;

    $returnRemarks = $isReturned
        ? $version->approvalSteps
            ->where('decision', 'RETURNED')
            ->pluck('remarks')
            ->filter()
        : collect();

    $premises = old(
        'premises',
        ($version->exists && $version->off_campus)
            ? 'OFF_CAMPUS'
            : 'ON_CAMPUS'
    );

    $supporting = $version->exists
        ? $version->supportingDocuments->where('is_current', true)
        : collect();

    $requestLetter = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );

    $ptc = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );

    $representsStudentActivity = (bool) old(
        'represents_student_activity',
        $version->represents_student_activity
    );
@endphp

<style>
    .borrow-request-ui {
        grid-column: 1 / -1;
        width: 100%;
        --br-navy: #102a43;
        --br-navy-2: #173f68;
        --br-gold: #c99a2e;
        --br-ink: #18324a;
        --br-muted: #66788a;
        --br-line: #d9e2ec;
        --br-soft: #f6f8fb;
        --br-selected: #f3f7fb;
        --br-danger: #9f2f2f;
    }

    .borrow-request-ui .request-card {
        overflow: hidden;
        border: 1px solid var(--br-line);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(16, 42, 67, .05);
    }

    .borrow-request-ui .request-card + .request-card {
        margin-top: 18px;
    }

    .borrow-request-ui .request-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        padding: 20px 22px 16px;
        border-bottom: 1px solid var(--br-line);
        background: linear-gradient(180deg, #fff 0%, #fbfcfe 100%);
    }

    .borrow-request-ui .request-card-title {
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }

    .borrow-request-ui .request-card-title .section-number {
        position: static;
        display: grid;
        place-items: center;
        flex: 0 0 34px;
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: var(--br-navy);
        color: #fff;
        font-size: .78rem;
        font-weight: 800;
    }

    .borrow-request-ui .request-card-title h2 {
        margin: 0;
        color: var(--br-ink);
        font-size: 1.02rem;
    }

    .borrow-request-ui .request-card-title .meta {
        margin: 4px 0 0;
        color: var(--br-muted);
        font-size: .84rem;
        line-height: 1.45;
    }

    .borrow-request-ui .request-card-body {
        padding: 20px 22px 22px;
    }

    .borrow-request-ui .details-top-row {
        display: grid;
        grid-template-columns: minmax(0, 1.75fr) minmax(0, 1.1fr) minmax(165px, .5fr);
        gap: 14px;
        align-items: end;
    }

    .borrow-request-ui .details-two-col {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .borrow-request-ui .field-group {
        margin-top: 16px;
    }

    .borrow-request-ui label > input,
    .borrow-request-ui label > select,
    .borrow-request-ui label > textarea {
        margin-top: 7px;
    }

    .borrow-request-ui .student-activity-box {
        margin-top: 16px;
        padding: 14px;
        border: 1px solid var(--br-line);
        border-radius: 12px;
        background: var(--br-soft);
    }

    .borrow-request-ui .student-activity-box .checkbox {
        margin: 0;
    }

    .borrow-request-ui .student-fields-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-top: 14px;
    }

    .borrow-request-ui .items-toolbar {
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 14px;
    }

    .borrow-request-ui .items-search-wrap {
        display: flex;
        align-items: end;
        gap: 8px;
        width: min(100%, 680px);
    }

    .borrow-request-ui .items-search-wrap label {
        flex: 1 1 auto;
    }

    .borrow-request-ui .clear-search-button {
        min-height: 42px;
        white-space: nowrap;
    }

    .borrow-request-ui .items-summary {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .borrow-request-ui .summary-chip {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        padding: 6px 10px;
        border: 1px solid var(--br-line);
        border-radius: 999px;
        background: #fff;
        color: var(--br-ink);
        font-size: .78rem;
        font-weight: 700;
    }

    .borrow-request-ui .summary-chip.is-policy {
        border-color: #ecd8a5;
        background: #fff9e8;
        color: #765814;
    }

    .borrow-request-ui .request-items-table table {
        min-width: 820px;
    }

    .borrow-request-ui .request-items-table thead th {
        padding-top: 11px;
        padding-bottom: 11px;
        background: var(--br-navy);
        color: #fff;
        font-size: .74rem;
        letter-spacing: .025em;
        text-transform: uppercase;
    }

    .borrow-request-ui .request-item-row {
        transition: background-color .16s ease, box-shadow .16s ease;
    }

    .borrow-request-ui .request-item-row.is-selected {
        background: var(--br-selected);
        box-shadow: inset 3px 0 0 var(--br-gold);
    }

    .borrow-request-ui .request-item-row[hidden] {
        display: none !important;
    }

    .borrow-request-ui .quantity-input {
        min-width: 92px;
        max-width: 110px;
    }

    .borrow-request-ui .item-availability {
        display: block;
        margin-top: 4px;
        color: var(--br-muted);
        white-space: nowrap;
    }

    .borrow-request-ui .review-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(260px, .48fr);
        gap: 16px;
        align-items: stretch;
    }

    .borrow-request-ui .review-note,
    .borrow-request-ui .review-summary-box {
        margin: 0;
        padding: 16px;
        border: 1px solid var(--br-line);
        border-radius: 12px;
        background: var(--br-soft);
    }

    .borrow-request-ui .review-summary-box strong {
        display: block;
        color: var(--br-ink);
        font-size: .85rem;
    }

    .borrow-request-ui .review-summary-box span {
        display: block;
        margin-top: 4px;
        color: var(--br-muted);
        font-size: .8rem;
        line-height: 1.45;
    }

    .borrow-request-ui .sticky-actions {
        position: sticky;
        bottom: 12px;
        z-index: 8;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 18px;
        padding: 12px;
        border: 1px solid var(--br-line);
        border-radius: 14px;
        background: rgba(255, 255, 255, .96);
        box-shadow: 0 10px 28px rgba(16, 42, 67, .10);
        backdrop-filter: blur(8px);
    }

    @media (max-width: 980px) {
        .borrow-request-ui .details-top-row,
        .borrow-request-ui .review-grid {
            grid-template-columns: 1fr 1fr;
        }

        .borrow-request-ui .details-top-row > :first-child,
        .borrow-request-ui .review-grid > :first-child {
            grid-column: 1 / -1;
        }

        .borrow-request-ui .items-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .borrow-request-ui .items-summary {
            justify-content: flex-start;
        }
    }

    @media (max-width: 720px) {
        .borrow-request-ui .details-top-row,
        .borrow-request-ui .details-two-col,
        .borrow-request-ui .student-fields-grid,
        .borrow-request-ui .review-grid {
            grid-template-columns: 1fr;
        }

        .borrow-request-ui .details-top-row > :first-child,
        .borrow-request-ui .review-grid > :first-child {
            grid-column: auto;
        }

        .borrow-request-ui .request-card-header,
        .borrow-request-ui .request-card-body {
            padding-left: 16px;
            padding-right: 16px;
        }

        .borrow-request-ui .items-search-wrap {
            align-items: stretch;
            flex-direction: column;
            width: 100%;
        }

        .borrow-request-ui .sticky-actions {
            position: static;
            flex-direction: column;
        }

        .borrow-request-ui .sticky-actions .button {
            width: 100%;
            justify-content: center;
        }
    }
</style>


<section class="page-heading request-form-heading">
    <div>
        <p class="eyebrow">Borrowing request</p>

        <h1>
            {{ $isReturned
                ? 'Revise your borrowing request'
                : ($borrowingRequest->exists
                    ? 'Edit request draft'
                    : 'Create a borrowing request')
            }}
        </h1>

    </div>

    <div class="step-chip">Draft details</div>
</section>


@if($isReturned)
<section class="content-area">
    <div class="action-panel action-warning" role="status">
        <div>
            <p class="eyebrow">Action required</p>
            <h2>Returned for Revision</h2>

            <p>
                Update the request using the review remarks below.
                Saving creates the next request version without removing the previous record.
            </p>
        </div>

        @if($returnRemarks->isNotEmpty())
            <ul>
                @foreach($returnRemarks as $remark)
                    <li>{{ $remark }}</li>
                @endforeach
            </ul>
        @else
            <p>
                No specific reviewer remark was recorded.
                Review the request details carefully before saving.
            </p>
        @endif
    </div>
</section>
@endif


<section class="content-area request-form-shell">

<form
    method="post"
    enctype="multipart/form-data"
    action="{{ $borrowingRequest->exists
        ? route('requests.update', $borrowingRequest)
        : route('requests.store')
    }}"
    class="form-grid"
    id="request-form"
>
    @csrf

    @if($borrowingRequest->exists)
        @method('PUT')
    @endif


    <div class="borrow-request-ui">
        {{-- =========================================================
             01 — REQUEST DETAILS
        ========================================================== --}}
        <section class="request-card" aria-labelledby="borrowing-details-heading">
            <div class="request-card-header">
                <div class="request-card-title">
                    <div class="section-number" aria-hidden="true">01</div>
                    <div>
                        <h2 id="borrowing-details-heading">Borrowing details</h2>
                        <p class="meta">Enter where, when, and why the property will be used. Premises applies to the whole request.</p>
                    </div>
                </div>
            </div>

            <div class="request-card-body">
                <div class="details-top-row">
                    <label>
                        Purpose of borrowing
                        <input name="purpose_event" value="{{ old('purpose_event', $version->purpose_event) }}" required>
                        @error('purpose_event')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        Location
                        <input name="location" value="{{ old('location', $version->location) }}" required>
                        @error('location')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        Premises
                        <select id="premises" name="premises" required>
                            <option value="ON_CAMPUS" @selected($premises === 'ON_CAMPUS')>On-campus</option>
                            <option value="OFF_CAMPUS" @selected($premises === 'OFF_CAMPUS')>Off-campus</option>
                        </select>
                        @error('premises')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <div class="details-two-col field-group">
                    <label>
                        Items needed from
                        <input
                            id="schedule_date"
                            type="date"
                            name="schedule_date"
                            value="{{ old('schedule_date', optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')) }}"
                            required
                        >
                        @error('schedule_date')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        Expected return date
                        <input
                            id="return_date"
                            type="date"
                            name="return_date"
                            value="{{ old('return_date', optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')) }}"
                            required
                        >
                        @error('return_date')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <div class="student-activity-box">
                    <label class="checkbox">
                        <input
                            id="represents_students"
                            type="checkbox"
                            name="represents_student_activity"
                            value="1"
                            @checked(old('represents_student_activity', $version->represents_student_activity))
                        >
                        This request represents a student activity
                    </label>

                    <div id="student-fields" class="student-fields-grid">
                        <label>
                            Student organization <small>(optional)</small>
                            <input
                                name="student_organization"
                                value="{{ old('student_organization', $version->student_organization) }}"
                            >
                        </label>

                        <label>
                            Program / Department / College
                            <input
                                name="represented_program_department"
                                value="{{ old('represented_program_department', $version->represented_program_department) }}"
                            >
                            @error('represented_program_department')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                        <label>
                            Year level
                            <input
                                name="represented_year_level"
                                value="{{ old('represented_year_level', $version->represented_year_level) }}"
                            >
                            @error('represented_year_level')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>
                </div>

                <label class="field-group" style="display:block;">
                    Additional note <small>(optional)</small>
                    <textarea name="remarks" rows="3">{{ old('remarks', $version->remarks) }}</textarea>
                    @error('remarks')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>
        </section>

        {{-- =========================================================
             02 — REQUESTED ITEMS
        ========================================================== --}}
        <section class="request-card" aria-labelledby="items-heading">
            <div class="request-card-header">
                <div class="request-card-title">
                    <div class="section-number" aria-hidden="true">02</div>
                    <div>
                        <h2 id="items-heading">Requested items</h2>
                        <p class="meta" id="availability-message">Choose valid borrowing dates to calculate current availability.</p>
                    </div>
                </div>
                <div class="items-summary" aria-live="polite">
                    <span class="summary-chip"><span id="selected-item-count">0</span>&nbsp;selected</span>
                    <span class="summary-chip is-policy" id="premises-policy-note">On-campus inventory</span>
                </div>
            </div>

            <div class="request-card-body">
                @error('item_ids')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                @error('items')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                @error('quantities')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                @error('premises')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <div class="items-toolbar">
                    <div class="items-search-wrap">
                        <label for="item-search">
                            Search available item
                            <input
                                id="item-search"
                                type="search"
                                placeholder="Search item name or description..."
                                autocomplete="off"
                            >
                        </label>
                        <button type="button" class="button secondary clear-search-button" id="clear-item-search">Clear</button>
                    </div>
                </div>

                <div class="table-wrap request-items-table">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Select</th>
                                <th scope="col">Item</th>
                                <th scope="col">Description</th>
                                <th scope="col">Unit</th>
                                <th scope="col">Qty</th>
                                <th scope="col">Condition</th>
                            </tr>
                        </thead>

                        <tbody id="request-items-body">
                        @foreach($items as $item)
                            @php
                                $requestItem = $selected->get($item->id);
                                $requestedQuantity = old(
                                    "quantities.$item->id",
                                    $requestItem?->requested_quantity ?? 0
                                );
                                $itemSearchText = strtolower(
                                    $item->unique_description.' '.
                                    ($item->specification ?? '')
                                );
                                $isBarricade = strcasecmp(trim($item->unique_description), 'Barricade') === 0;

@endphp

                            <tr
                                class="request-item-row"
                                data-item-id="{{ $item->id }}"
                                data-item-name="{{ strtolower($item->unique_description) }}"
                                data-search="{{ $itemSearchText }}"
                                data-barricade="{{ $isBarricade ? '1' : '0' }}"
                            >
                                <td data-label="Select">
                                    <input
                                        class="item-select-checkbox"
                                        type="checkbox"
                                        name="item_ids[]"
                                        value="{{ $item->id }}"
                                        aria-label="Select {{ $item->unique_description }}"
                                        @checked((float) $requestedQuantity > 0)
                                    >
                                </td>

                                <td data-label="Item">
                                    <strong>{{ $item->unique_description }}</strong>

                                    @if($item->laundry_required)
                                        <span class="badge">Laundry required</span>
                                    @endif
                                </td>

                                <td data-label="Description">
                                    {{ $item->specification ?: '—' }}
                                </td>

                                <td data-label="Unit">
                                    {{ $item->unit->unit_name }}
                                </td>

                                <td data-label="Qty">
                                    <input
                                        class="quantity-input"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        max="{{ $item->total_quantity }}"
                                        name="quantities[{{ $item->id }}]"
                                        aria-label="Requested quantity for {{ $item->unique_description }}"
                                        value="{{ $requestedQuantity }}"
                                    >

                                    <small
                                        class="item-availability"
                                        data-item="{{ $item->id }}"
                                    >
                                        Select dates
                                    </small>
                                </td>

                                <td data-label="Condition">
                                    <x-status-badge :status="$item->condition_code" />
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div id="no-items-found" class="empty-state" hidden>
                    No eligible item matches the current premises and search.
                </div>
            </div>
        </section>

        {{-- =========================================================
             03 — REVIEW AND SAVE
        ========================================================== --}}

        {{-- =========================================================
             03 — SUPPORTING DOCUMENTS
        ========================================================== --}}
        <section
            class="request-card"
            aria-labelledby="supporting-documents-heading"
        >
            <div class="request-card-header">
                <div class="request-card-title">
                    <div
                        class="section-number"
                        aria-hidden="true"
                    >
                        03
                    </div>

                    <div>
                        <h2 id="supporting-documents-heading">
                            Supporting documents
                        </h2>

                        <p class="meta">
                            These files may be added while the request is still
                            a draft. Required documents are checked again before
                            the request can be submitted to SPMU.
                        </p>
                    </div>
                </div>
            </div>

            <div class="request-card-body">
                <div class="details-two-col">

                    <label>
                        Approved / Signed Borrowing Request Letter
                        <input
                            type="file"
                            name="approved_request_letter"
                            accept=".pdf,.png,.jpg,.jpeg,.webp"
                        >

                        <small class="meta">
                            Upload the scanned fully signed request letter when
                            it is available. PDF, PNG, JPG, JPEG, or WebP;
                            maximum 10 MB.
                        </small>

                        @if($requestLetter)
                            <small class="meta">
                                ✓ A current Borrowing Request Letter is already
                                attached. Uploading a new file will create a new
                                document version.
                            </small>
                        @endif

                        @error('approved_request_letter')
                            <small class="field-error">
                                {{ $message }}
                            </small>
                        @enderror
                    </label>

                    <label
                        id="ptc-upload-group"
                        @if(!$representsStudentActivity) hidden @endif
                    >
                        Permission to Conduct Letter
                        <input
                            type="file"
                            name="permission_to_conduct_letter"
                            accept=".pdf,.png,.jpg,.jpeg,.webp"
                        >

                        <small class="meta">
                            Required for student activity / organization
                            requests before submission to SPMU.
                        </small>

                        @if($ptc)
                            <small class="meta">
                                ✓ A current Permission to Conduct Letter is
                                already attached. Uploading a new file will
                                create a new document version.
                            </small>
                        @endif

                        @error('permission_to_conduct_letter')
                            <small class="field-error">
                                {{ $message }}
                            </small>
                        @enderror
                    </label>

                </div>
            </div>
        </section>
<section class="request-card" aria-labelledby="review-heading">
            <div class="request-card-header">
                <div class="request-card-title">
                    <div class="section-number" aria-hidden="true">04</div>
                    <div>
                        <h2 id="review-heading">Review and save your draft</h2>
                        <p class="meta">Saving creates the request draft and official preview. It does not submit the request for approval.</p>
                    </div>
                </div>
            </div>

            <div class="request-card-body">
                <div class="review-grid">
                    <div class="review-note">
                        <strong>Before saving</strong>
                        <ul>
                            <li>Check the purpose, location, premises, and borrowing period.</li>
                            <li>Only selected items with a quantity greater than zero are saved.</li>
                            <li>Availability is checked again when the draft is saved.</li>
                        </ul>
                    </div>

                    <div class="review-summary-box">
                        <strong>What happens next?</strong>
                        <span>The system generates the official request preview. Review that document on the next screen before continuing the request process.</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="actions sticky-actions">
            <a class="button secondary" href="{{ route('requests.index') }}">Cancel</a>
            <button type="submit" class="button primary ui-pressable">Save draft and generate preview</button>
        </div>
    </div>

</form>

</section>

<script>
document.addEventListener('DOMContentLoaded', () => {

    /*
    |--------------------------------------------------------------------------
    | Student activity fields
    |--------------------------------------------------------------------------
    */

    const studentToggle = document.getElementById('represents_students');
    const studentFields = document.getElementById('student-fields');

    function toggleStudentFields() {
        if (!studentToggle || !studentFields) {
            return;
        }

        studentFields.classList.toggle(
            'is-hidden',
            !studentToggle.checked
        );

        studentFields
            .querySelectorAll('input')
            .forEach((input) => {
                if (input.name !== 'student_organization') {
                    input.required = studentToggle.checked;
                }
            });
    }

    if (studentToggle) {
        studentToggle.addEventListener(
            'change',
            toggleStudentFields
        );

        toggleStudentFields();
    }


    /*
    |--------------------------------------------------------------------------
    | Request-level Premises
    |--------------------------------------------------------------------------
    */

    const premisesSelect =
        document.getElementById('premises');

    const premisesPolicyNote =
        document.getElementById('premises-policy-note');

    /*
     * Premises eligibility rule:
     *
     * ON_CAMPUS  = show all otherwise eligible inventory items.
     * OFF_CAMPUS = show Barricade only.
     *
     * If the borrower switches to OFF_CAMPUS after selecting
     * other items, those now-ineligible selections are cleared.
     */
    function syncPremises() {
        if (!premisesSelect) {
            return;
        }

        const isOffCampus =
            premisesSelect.value === 'OFF_CAMPUS';

        if (premisesPolicyNote) {
            premisesPolicyNote.textContent =
                isOffCampus
                    ? 'Off-campus: Barricade only'
                    : 'On-campus: All eligible items';
        }

        document
            .querySelectorAll('.request-item-row')
            .forEach((row) => {

                const isBarricade =
                    row.dataset.barricade === '1';

                /*
                 * Clear any item that becomes invalid when the
                 * borrower changes the request to off-campus.
                 */
                if (isOffCampus && !isBarricade) {
                    const checkbox =
                        row.querySelector('.item-select-checkbox');

                    const quantity =
                        row.querySelector('.quantity-input');

                    if (checkbox) {
                        checkbox.checked = false;
                    }

                    if (quantity) {
                        quantity.value = 0;
                    }
                }
            });

        /*
         * Visibility itself is handled together with the search
         * filter so both rules always work at the same time.
         */
        applySearchFilter();
        updateSelectionUI();
    }

    if (premisesSelect) {
        premisesSelect.addEventListener(
            'change',
            syncPremises
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Search items
    |--------------------------------------------------------------------------
    */

    const searchInput =
        document.getElementById('item-search');

    const noItemsFound =
        document.getElementById('no-items-found');

    const selectedItemCount =
        document.getElementById('selected-item-count');

    const clearItemSearch =
        document.getElementById('clear-item-search');

    function updateSelectionUI() {
        let selectedCount = 0;

        document
            .querySelectorAll('.request-item-row')
            .forEach((row) => {
                const checkbox = row.querySelector('.item-select-checkbox');
                const quantity = row.querySelector('.quantity-input');
                const isSelected = Boolean(checkbox?.checked) && Number(quantity?.value || 0) > 0;

                row.classList.toggle('is-selected', isSelected);

                if (isSelected) {
                    selectedCount++;
                }
            });

        if (selectedItemCount) {
            selectedItemCount.textContent = String(selectedCount);
        }
    }

    function applySearchFilter() {

        const query =
            (searchInput?.value || '')
                .trim()
                .toLowerCase();

        const isOffCampus =
            premisesSelect?.value === 'OFF_CAMPUS';

        let visibleCount = 0;

        document
            .querySelectorAll('.request-item-row')
            .forEach((row) => {

                const searchable =
                    (row.dataset.search || '')
                        .toLowerCase();

                const isBarricade =
                    row.dataset.barricade === '1';

                const matchesSearch =
                    !query || searchable.includes(query);

                /*
                 * On-campus: every item may pass this premises rule.
                 * Off-campus: Barricade is the only item allowed.
                 */
                const matchesPremises =
                    !isOffCampus || isBarricade;

                const visible =
                    matchesSearch && matchesPremises;

                row.hidden = !visible;

                if (visible) {
                    visibleCount++;
                }
            });

        if (noItemsFound) {
            noItemsFound.hidden =
                visibleCount !== 0;
        }
    }

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            applySearchFilter
        );
    }


    if (clearItemSearch) {
        clearItemSearch.addEventListener('click', () => {
            if (!searchInput) {
                return;
            }

            searchInput.value = '';
            applySearchFilter();
            searchInput.focus();
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Checkbox / quantity synchronization
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.item-select-checkbox')
        .forEach((checkbox) => {

            checkbox.addEventListener(
                'change',
                () => {

                    const row =
                        checkbox.closest('.request-item-row');

                    const quantity =
                        row?.querySelector('.quantity-input');

                    if (!quantity) {
                        return;
                    }

                    if (checkbox.checked) {

                        if (
                            !quantity.value ||
                            Number(quantity.value) <= 0
                        ) {
                            quantity.value = 1;
                        }

                        quantity.focus();
                        quantity.select();

                    } else {
                        quantity.value = 0;
                    }

                    updateSelectionUI();
                }
            );

        });


    document
        .querySelectorAll('.quantity-input')
        .forEach((quantity) => {

            quantity.addEventListener(
                'input',
                () => {

                    const row =
                        quantity.closest('.request-item-row');

                    const checkbox =
                        row?.querySelector('.item-select-checkbox');

                    if (!checkbox) {
                        return;
                    }

                    checkbox.checked =
                        Number(quantity.value) > 0;

                    updateSelectionUI();
                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    const neededFrom =
        document.getElementById('schedule_date');

    const returnDueAt =
        document.getElementById('return_date');

    const availabilityMessage =
        document.getElementById('availability-message');

    let availabilityTimer;


    async function refreshAvailability() {

        const from =
            neededFrom?.value;

        const to =
            returnDueAt?.value;

        if (!from || !to) {
            return;
        }

        clearTimeout(availabilityTimer);

        availabilityTimer =
            setTimeout(
                async () => {

                    if (availabilityMessage) {
                        availabilityMessage.textContent =
                            'Checking real-time availability…';
                    }

                    try {

                        const response =
                            await fetch(
                                `{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
                                {
                                    headers: {
                                        Accept: 'application/json'
                                    }
                                }
                            );

                        if (!response.ok) {
                            throw new Error(
                                'Availability request failed.'
                            );
                        }

                        const data =
                            await response.json();


                        document
                            .querySelectorAll('.item-availability')
                            .forEach((node) => {

                                const itemId =
                                    node.dataset.item;

                                const balance =
                                    data[itemId];

                                if (!balance) {
                                    return;
                                }

                                node.textContent =
                                    `Available: ${balance.available}`;

                                const quantity =
                                    document.querySelector(
                                        `[name="quantities[${itemId}]"]`
                                    );

                                if (quantity) {
                                    quantity.max =
                                        balance.available;
                                }

                            });


                        if (availabilityMessage) {
                            availabilityMessage.textContent =
                                'Availability is based on the complete selected borrowing period and will be checked again when the request is saved.';
                        }

                    } catch (error) {

                        if (availabilityMessage) {
                            availabilityMessage.textContent =
                                'Enter a valid borrowing period to calculate availability.';
                        }

                    }

                },
                250
            );
    }


    if (neededFrom) {
        neededFrom.addEventListener(
            'change',
            refreshAvailability
        );
    }


    if (returnDueAt) {
        returnDueAt.addEventListener(
            'change',
            refreshAvailability
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initial page state
    |--------------------------------------------------------------------------
    */

    syncPremises();
    applySearchFilter();
    updateSelectionUI();
    refreshAvailability();

});
</script>


<script>
document.addEventListener('DOMContentLoaded', () => {
    const studentActivityToggle =
        document.getElementById('represents_students');

    const ptcUploadGroup =
        document.getElementById('ptc-upload-group');

    function syncPtcUploadVisibility() {
        if (!studentActivityToggle || !ptcUploadGroup) {
            return;
        }

        ptcUploadGroup.hidden =
            !studentActivityToggle.checked;
    }

    studentActivityToggle?.addEventListener(
        'change',
        syncPtcUploadVisibility
    );

    syncPtcUploadVisibility();
});
</script>
@endsection
