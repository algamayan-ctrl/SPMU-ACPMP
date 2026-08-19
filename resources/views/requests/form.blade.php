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
@endphp


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

        <p>
            Complete the borrowing details, schedule, and requested items below.
            Availability applies to the entire selected period.
        </p>
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


    {{-- =========================================================
         01 — BORROWING DETAILS
    ========================================================== --}}
    <section
        class="card form-section"
        aria-labelledby="borrowing-details-heading"
    >
        <div class="section-number" aria-hidden="true">01</div>

        <div>
            <h2 id="borrowing-details-heading">
                Borrowing details
            </h2>

            <p class="meta">
                Describe the purpose and where the requested property will be used.
            </p>
        </div>


        <div
            class="full-span"
            style="
                display: grid;
                grid-template-columns: minmax(0, 1.7fr) minmax(0, 1.15fr) minmax(170px, 0.55fr);
                gap: 14px;
                align-items: end;
            "
        >

            <label>
                Purpose of borrowing

                <input
                    name="purpose_event"
                    value="{{ old('purpose_event', $version->purpose_event) }}"
                    required
                >

                @error('purpose_event')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>


            <label>
                Location

                <input
                    name="location"
                    value="{{ old('location', $version->location) }}"
                    required
                >

                @error('location')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>


            <label>
                Premises

                <select
                    id="premises"
                    name="premises"
                    required
                >
                    <option
                        value="ON_CAMPUS"
                        @selected($premises === 'ON_CAMPUS')
                    >
                        On-campus
                    </option>

                    <option
                        value="OFF_CAMPUS"
                        @selected($premises === 'OFF_CAMPUS')
                    >
                        Off-campus
                    </option>
                </select>

                @error('premises')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>

        </div>


        <label class="full-span">
            Additional note to reviewers
            <small>(optional)</small>

            <textarea name="remarks">{{ old('remarks', $version->remarks) }}</textarea>

            @error('remarks')
                <small class="field-error">
                    {{ $message }}
                </small>
            @enderror
        </label>
    </section>


    {{-- =========================================================
         02 — BORROWING SCHEDULE
    ========================================================== --}}
    <section
        class="card form-section"
        aria-labelledby="schedule-heading"
    >
        <div class="section-number" aria-hidden="true">02</div>

        <div>
            <h2 id="schedule-heading">
                Borrowing schedule
            </h2>

            <p class="meta">
                Choose the complete period when the items will be needed.
            </p>
        </div>


        <div class="form-columns full-span">

            <label>
                Items needed from

                <input
                    id="needed_from"
                    type="datetime-local"
                    name="needed_from"
                    value="{{ old(
                        'needed_from',
                        optional($version->needed_from)->format('Y-m-d\TH:i')
                    ) }}"
                    required
                >

                @error('needed_from')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>


            <label>
                Expected return date

                <input
                    id="return_due_at"
                    type="datetime-local"
                    name="return_due_at"
                    value="{{ old(
                        'return_due_at',
                        optional($version->return_due_at)->format('Y-m-d\TH:i')
                    ) }}"
                    required
                >

                @error('return_due_at')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>

        </div>
    </section>


    {{-- =========================================================
         03 — REPRESENTED ORGANIZATION / PROGRAM
    ========================================================== --}}
    <section
        class="card form-section"
        aria-labelledby="represented-activity-heading"
    >
        <div class="section-number" aria-hidden="true">03</div>

        <div>
            <h2 id="represented-activity-heading">
                Represented organization or program
            </h2>

            <p class="meta">
                Complete this only when borrowing on behalf of a student activity.
                You remain the accountable borrower.
            </p>
        </div>


        <label class="checkbox full-span">
            <input
                id="represents_students"
                type="checkbox"
                name="represents_student_activity"
                value="1"
                @checked(
                    old(
                        'represents_student_activity',
                        $version->represents_student_activity
                    )
                )
            >

            This request represents a student activity
        </label>


        <div
            id="student-fields"
            class="form-columns full-span"
        >
            <label>
                Student organization
                <small>(optional)</small>

                <input
                    name="student_organization"
                    value="{{ old(
                        'student_organization',
                        $version->student_organization
                    ) }}"
                >
            </label>


            <label>
                Program or department

                <input
                    name="represented_program_department"
                    value="{{ old(
                        'represented_program_department',
                        $version->represented_program_department
                    ) }}"
                >

                @error('represented_program_department')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror
            </label>
        </div>

    </section>


    {{-- =========================================================
         04 — REQUESTED ITEMS
    ========================================================== --}}
    <section
        class="card form-section item-selection-section"
        aria-labelledby="items-heading"
    >
        <div class="section-number" aria-hidden="true">04</div>

        <div>
            <h2 id="items-heading">
                Requested items
            </h2>

            <p
                class="meta"
                id="availability-message"
            >
                Choose valid borrowing dates to calculate current availability.
            </p>
        </div>


        @error('item_ids')
            <p class="field-error full-span">
                {{ $message }}
            </p>
        @enderror

        @error('items')
            <p class="field-error full-span">
                {{ $message }}
            </p>
        @enderror

        @error('quantities')
            <p class="field-error full-span">
                {{ $message }}
            </p>
        @enderror

        @error('premises')
            <p class="field-error full-span">
                {{ $message }}
            </p>
        @enderror


        {{-- SEARCH --}}
        <div class="full-span request-item-search">
            <label for="item-search">
                Search available item

                <input
                    id="item-search"
                    type="search"
                    placeholder="Search item name, description, or Item ID..."
                    autocomplete="off"
                >
            </label>
        </div>


        <div class="table-wrap full-span request-items-table">

            <table>
                <thead>
                    <tr>
                        <th scope="col">Select</th>
                        <th scope="col">Item</th>
                        <th scope="col">Description</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Qty</th>
                        <th scope="col">Premises</th>
                        <th scope="col">Actions</th>
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
                            $item->id.' '.
                            $item->unique_description.' '.
                            ($item->specification ?? '')
                        );

                        $isBarricade = strcasecmp(
                            trim($item->unique_description),
                            'Barricade'
                        ) === 0;
                    @endphp


                    <tr
                        class="request-item-row"
                        data-item-id="{{ $item->id }}"
                        data-item-name="{{ strtolower($item->unique_description) }}"
                        data-search="{{ $itemSearchText }}"
                        data-barricade="{{ $isBarricade ? '1' : '0' }}"
                    >

                        {{-- SELECT --}}
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


                        {{-- ITEM --}}
                        <td data-label="Item">
                            <strong>
                                {{ $item->unique_description }}
                            </strong>

                            <small>
                                Item ID: {{ $item->id }}
                            </small>

                            @if($item->laundry_required)
                                <span class="badge">
                                    Laundry required
                                </span>
                            @endif
                        </td>


                        {{-- DESCRIPTION --}}
                        <td data-label="Description">
                            {{ $item->specification ?: '—' }}
                        </td>


                        {{-- UNIT --}}
                        <td data-label="Unit">
                            {{ $item->unit->unit_name }}
                        </td>


                        {{-- QTY --}}
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


                        {{-- PREMISES --}}
                        <td data-label="Premises">
                            <span class="locked-value premises-display">
                                {{ $premises === 'OFF_CAMPUS'
                                    ? 'Off-campus'
                                    : 'On-campus'
                                }}
                            </span>
                        </td>


                        {{-- ACTIONS --}}
                        <td data-label="Actions">
                            <div class="request-item-actions">

                                <button
                                    type="button"
                                    class="button secondary ui-pressable item-edit-button"
                                    data-item="{{ $item->id }}"
                                    title="Edit requested quantity"
                                >
                                    Edit
                                </button>


                                <button
                                    type="button"
                                    class="button danger ui-pressable item-delete-button"
                                    data-item="{{ $item->id }}"
                                    data-name="{{ $item->unique_description }}"
                                    title="Remove item from this borrowing request"
                                >
                                    Delete
                                </button>


                                <button
                                    type="button"
                                    class="button secondary ui-pressable item-history-button"
                                    data-item="{{ $item->id }}"
                                    data-name="{{ $item->unique_description }}"
                                    title="View transaction history"
                                >
                                    Transaction History
                                </button>


                                <button
                                    type="button"
                                    class="button secondary ui-pressable item-stock-card-button"
                                    data-item="{{ $item->id }}"
                                    data-name="{{ $item->unique_description }}"
                                    title="View stock card"
                                >
                                    Stock Card
                                </button>

                            </div>
                        </td>

                    </tr>

                @endforeach

                </tbody>
            </table>

        </div>


        <div
            id="no-items-found"
            class="empty-state full-span"
            hidden
        >
            No matching available item was found.
        </div>

    </section>


    {{-- =========================================================
         05 — REVIEW
    ========================================================== --}}
    <section
        class="card form-section review-section"
        aria-labelledby="review-heading"
    >
        <div class="section-number" aria-hidden="true">
            05
        </div>

        <div>
            <h2 id="review-heading">
                Review and save your draft
            </h2>

            <p class="meta">
                Saving does not submit the request for approval.
            </p>
        </div>


        <div class="full-span review-note">

            <p>
                The system will save your request and generate an official preview.
                Review the generated document on the next screen.
            </p>

            <ul>
                <li>
                    Availability will be checked again before the request is saved.
                </li>

                <li>
                    Only selected items with a quantity greater than zero are saved.
                </li>

                <li>
                    The selected Premises applies to the entire request.
                </li>
            </ul>

        </div>

    </section>


    <div class="actions sticky-actions">

        <button
            type="submit"
            class="button primary ui-pressable"
        >
            Save draft and generate preview
        </button>

        <a
            class="button secondary"
            href="{{ route('requests.index') }}"
        >
            Cancel
        </a>

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

    const premisesSelect = document.getElementById('premises');

    function syncPremises() {
        if (!premisesSelect) {
            return;
        }

        const isOffCampus =
            premisesSelect.value === 'OFF_CAMPUS';

        const premisesLabel =
            isOffCampus
                ? 'Off-campus'
                : 'On-campus';

        document
            .querySelectorAll('.premises-display')
            .forEach((node) => {
                node.textContent = premisesLabel;
            });

        /*
         * OFF_CAMPUS:
         * only Barricade remains visible/selectable.
         *
         * ON_CAMPUS:
         * all valid borrowable items are visible.
         */
        document
            .querySelectorAll('.request-item-row')
            .forEach((row) => {

                const isBarricade =
                    row.dataset.barricade === '1';

                if (isOffCampus && !isBarricade) {

                    row.hidden = true;

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

                } else {
                    row.hidden = false;
                }

            });

        applySearchFilter();
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

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Edit button
    |--------------------------------------------------------------------------
    |
    | Edit affects only the requested quantity.
    | It does NOT edit the inventory master record.
    |
    */

    document
        .querySelectorAll('.item-edit-button')
        .forEach((button) => {

            button.addEventListener(
                'click',
                () => {

                    const row =
                        button.closest('.request-item-row');

                    const checkbox =
                        row?.querySelector('.item-select-checkbox');

                    const quantity =
                        row?.querySelector('.quantity-input');

                    if (!quantity || !checkbox) {
                        return;
                    }

                    checkbox.checked = true;

                    if (
                        !quantity.value ||
                        Number(quantity.value) <= 0
                    ) {
                        quantity.value = 1;
                    }

                    quantity.focus();
                    quantity.select();

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Delete button
    |--------------------------------------------------------------------------
    |
    | Removes the item only from the current borrowing request.
    | It does NOT delete the InventoryItem record.
    |
    */

    document
        .querySelectorAll('.item-delete-button')
        .forEach((button) => {

            button.addEventListener(
                'click',
                () => {

                    const itemName =
                        button.dataset.name || 'this item';

                    const confirmed =
                        window.confirm(
                            `Remove ${itemName} from this borrowing request?`
                        );

                    if (!confirmed) {
                        return;
                    }

                    const row =
                        button.closest('.request-item-row');

                    const checkbox =
                        row?.querySelector('.item-select-checkbox');

                    const quantity =
                        row?.querySelector('.quantity-input');

                    if (checkbox) {
                        checkbox.checked = false;
                    }

                    if (quantity) {
                        quantity.value = 0;
                    }

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Transaction History / Stock Card
    |--------------------------------------------------------------------------
    |
    | The buttons are intentionally real <button> controls.
    |
    | Their real transaction-ledger / stock-card backend will be connected
    | to dedicated read-only endpoints. Do not point these buttons to
    | inventory.edit because borrowers must never edit inventory master data.
    |
    */

    document
        .querySelectorAll('.item-history-button')
        .forEach((button) => {

            button.addEventListener(
                'click',
                () => {

                    const event =
                        new CustomEvent(
                            'request:item-history',
                            {
                                detail: {
                                    itemId: button.dataset.item,
                                    itemName: button.dataset.name
                                }
                            }
                        );

                    document.dispatchEvent(event);

                }
            );

        });


    document
        .querySelectorAll('.item-stock-card-button')
        .forEach((button) => {

            button.addEventListener(
                'click',
                () => {

                    const event =
                        new CustomEvent(
                            'request:item-stock-card',
                            {
                                detail: {
                                    itemId: button.dataset.item,
                                    itemName: button.dataset.name
                                }
                            }
                        );

                    document.dispatchEvent(event);

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    const neededFrom =
        document.getElementById('needed_from');

    const returnDueAt =
        document.getElementById('return_due_at');

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
    refreshAvailability();

});
</script>

@endsection