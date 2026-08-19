@extends('layouts.app', ['title' => $item->unique_description])

@section('content')

@php
    $total = (float) ($balance['total'] ?? 0);
    $available = (float) ($balance['borrower_available'] ?? 0);
    $reserved = (float) ($balance['reserved'] ?? 0);
    $issued = (float) ($balance['borrowed'] ?? 0);
    $laundry = (float) ($balance['laundry'] ?? 0);
    $incident = (float) ($balance['incident'] ?? 0);
    $unavailable = max(0, $total - $available - $reserved - $issued);

    $damagedMaintenance = min($total, (float) ($balance['damaged_maintenance'] ?? 0));
    $lost = min($total, (float) ($balance['lost'] ?? 0));
    $stolen = min($total, (float) ($balance['stolen'] ?? 0));
    $destroyed = min($total, (float) ($balance['destroyed'] ?? 0));
    $condemned = min($total, (float) ($balance['condemned'] ?? 0));

    $knownNonGood = min(
        $total,
        $damagedMaintenance + $lost + $stolen + $destroyed + $condemned
    );

    $recordedGood = $item->condition_code === 'SERVICEABLE'
        ? max(0, $total - $knownNonGood)
        : 0;

    $borrowerStatus = $available <= 0 || $item->condition_code !== 'SERVICEABLE'
        ? 'UNAVAILABLE'
        : ($available < $total ? 'LIMITED' : 'AVAILABLE');
@endphp

<section class="page-heading inventory-detail-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'Inventory reference' : 'Inventory details' }}</p>
        <h1>{{ $item->unique_description }}</h1>
        <p>{{ $item->category->category_name }} &middot; {{ $item->unit->unit_name }}</p>
    </div>

    <a class="button secondary ui-pressable" href="{{ route('inventory.index') }}">
        Back to Inventory
    </a>
</section>

<section class="content-area inventory-detail-page">
    @if($isBorrower)
        <article class="card borrower-inventory-detail-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Current reference availability</p>
                    <h2>Available for borrowing</h2>
                </div>

                @if($borrowerStatus === 'AVAILABLE')
                    <x-status-badge status="AVAILABLE" label="Available" />
                @elseif($borrowerStatus === 'LIMITED')
                    <x-status-badge status="LOW_STOCK" label="Limited" />
                @else
                    <x-status-badge status="UNAVAILABLE" label="Unavailable" />
                @endif
            </div>

            <div class="borrower-inventory-hero">
                <strong>{{ $available + 0 }}</strong>
                <span>{{ $item->unit->unit_name }} available</span>
            </div>

            <dl class="detail-list compact">
                <dt>Description</dt>
                <dd>{{ $item->specification ?: 'No additional description.' }}</dd>

                <dt>Condition</dt>
                <dd>
                    @if($item->condition_code === 'SERVICEABLE')
                        <span class="inventory-good-label"><x-icon name="success" size="16" /> Good / Serviceable</span>
                    @else
                        <span>Not currently suitable for borrowing</span>
                    @endif
                </dd>

                <dt>Use</dt>
                <dd>{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</dd>

                @if($item->laundry_required)
                    <dt>After use</dt>
                    <dd>Laundry processing is required after return.</dd>
                @endif
            </dl>
        </article>

        <div class="inventory-reference-note" role="note">
            <x-icon name="information" size="18" />
            <div>
                <strong>Reference only — this is not a reservation.</strong>
                <p>
                    Availability can change while your request is under review. Submitting a request does not hold this quantity. SPMU verifies the final quantity during review. Only an approved allocation reduces the quantity shown as available.
                </p>
            </div>
        </div>
    @else
        <div class="inventory-detail-summary" aria-label="Quantity status breakdown">
            <article class="inventory-summary-card">
                <span>Total Quantity</span>
                <strong>{{ $total + 0 }}</strong>
            </article>
            <article class="inventory-summary-card is-good">
                <span>Available</span>
                <strong>{{ $available + 0 }}</strong>
            </article>
            <article class="inventory-summary-card">
                <span>Reserved</span>
                <strong>{{ $reserved + 0 }}</strong>
            </article>
            <article class="inventory-summary-card">
                <span>Issued</span>
                <strong>{{ $issued + 0 }}</strong>
            </article>
            <article class="inventory-summary-card">
                <span>Unavailable</span>
                <strong>{{ $unavailable + 0 }}</strong>
            </article>
        </div>

        <div class="inventory-detail-grid">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Quantity status</p>
                        <h2>Operational breakdown</h2>
                    </div>
                </div>

                <dl class="detail-list compact inventory-breakdown-list">
                    <dt>Available for allocation</dt>
                    <dd><strong>{{ $available + 0 }}</strong></dd>

                    <dt>Reserved</dt>
                    <dd><strong>{{ $reserved + 0 }}</strong><small>Approved allocation not yet issued</small></dd>

                    <dt>Issued</dt>
                    <dd><strong>{{ $issued + 0 }}</strong><small>Currently under borrower custody</small></dd>

                    <dt>In laundry</dt>
                    <dd><strong>{{ $laundry + 0 }}</strong></dd>

                    <dt>In accountability / incident handling</dt>
                    <dd><strong>{{ $incident + 0 }}</strong></dd>

                    <dt>Unavailable total</dt>
                    <dd><strong>{{ $unavailable + 0 }}</strong></dd>
                </dl>
            </article>

            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Physical condition</p>
                        <h2>Condition breakdown</h2>
                    </div>
                    <x-status-badge :status="$item->condition_code" />
                </div>

                <dl class="detail-list compact inventory-breakdown-list">
                    <dt>Good / serviceable</dt>
                    <dd><strong>{{ $recordedGood + 0 }}</strong></dd>

                    <dt>Damaged / under repair</dt>
                    <dd><strong>{{ $damagedMaintenance + 0 }}</strong></dd>

                    @if($lost > 0)
                        <dt>Lost</dt>
                        <dd><strong>{{ $lost + 0 }}</strong></dd>
                    @endif

                    @if($stolen > 0)
                        <dt>Stolen</dt>
                        <dd><strong>{{ $stolen + 0 }}</strong></dd>
                    @endif

                    @if($destroyed > 0)
                        <dt>Destroyed</dt>
                        <dd><strong>{{ $destroyed + 0 }}</strong></dd>
                    @endif

                    @if($condemned > 0)
                        <dt>Condemned</dt>
                        <dd><strong>{{ $condemned + 0 }}</strong></dd>
                    @endif
                </dl>

                <p class="inventory-data-note">
                    The current database records Serviceable, Damaged / Maintenance, Condemned, and incident dispositions. Fair/Poor quantity bands are not invented when they are not stored by the system.
                </p>
            </article>
        </div>

        <article class="card inventory-master-detail-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Inventory master record</p>
                    <h2>Item information</h2>
                </div>

                @if($isSpmu)
                    <a class="button secondary small ui-pressable" href="{{ route('inventory.edit', $item) }}">
                        <x-icon name="edit" size="16" />
                        Edit item
                    </a>
                @endif
            </div>

            <dl class="detail-list compact">
                <dt>Description</dt>
                <dd>{{ $item->specification ?: 'No additional description.' }}</dd>

                <dt>Category</dt>
                <dd>{{ $item->category->category_name }}</dd>

                <dt>Unit of measure</dt>
                <dd>{{ $item->unit->unit_name }}</dd>

                <dt>Borrowing eligibility</dt>
                <dd>{{ $item->borrowable ? 'Borrowable' : 'Not borrowable' }}</dd>

                <dt>Use restriction</dt>
                <dd>{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</dd>

                <dt>Laundry requirement</dt>
                <dd>{{ $item->laundry_required ? 'Required after use' : 'Not required' }}</dd>
            </dl>
        </article>
    @endif
</section>

@endsection
