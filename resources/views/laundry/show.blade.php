@extends('layouts.app', ['title' => 'Laundry '.$job->custody->custody_no])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">Laundry Request</p>
        <h1>{{ $job->custody->custody_no }}</h1>
        <p>
            Borrower: <strong>{{ $job->custody->borrower->full_name }}</strong>
            · Request {{ $job->custody->request->request_no }}
        </p>
    </div>

    <x-status-badge :status="$job->status" />
</section>

<section class="content-grid">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Physical form</p>
                <h2>Laundry Form</h2>
            </div>
        </div>

        <p>
            Use the physical form to write the received quantity, condition,
            tear/damage/stain findings, completed quantity, remarks, dates,
            your name, and wet signature.
        </p>

        @if($job->document)
            <a
                class="button secondary ui-pressable"
                href="{{ route('documents.download', $job->document) }}"
                target="_blank"
                rel="noopener"
            >
                View / Print Laundry Form
            </a>
        @else
            <div class="callout warning">
                <strong>Laundry Form is not available yet.</strong>
                <p>Ask SPMU to regenerate the form before continuing.</p>
            </div>
        @endif
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Linen from borrower</p>
                <h2>Items covered by this form</h2>
            </div>
        </div>

        <div class="document-list">
            @foreach($job->lines as $line)
                <article>
                    <div>
                        <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                        <small>
                            {{ $line->issued_quantity + 0 }}
                            {{ $line->custodyLine->requestItem->unit_snapshot }}
                        </small>
                    </div>
                </article>
            @endforeach
        </div>
    </article>
</section>

@if(in_array($job->status, ['FOR_LAUNDRY', 'FORM_REPLACEMENT_REQUIRED'], true))
<section class="content-area narrow">
    <form
        method="post"
        action="{{ route('laundry.upload-form', $job) }}"
        enctype="multipart/form-data"
        class="card form-grid"
    >
        @csrf

        <div class="card-header">
            <div>
                <p class="eyebrow">When laundry is complete</p>
                <h2>
                    {{
                        $job->status === 'FORM_REPLACEMENT_REQUIRED'
                            ? 'Upload replacement accomplished form'
                            : 'Upload accomplished Laundry Form'
                    }}
                </h2>
            </div>
        </div>

        @if($job->status === 'FORM_REPLACEMENT_REQUIRED')
            <div class="callout warning">
                <strong>SPMU requested a replacement scan.</strong>
                <p>Please scan or photograph the accomplished form clearly and upload it again.</p>
            </div>
        @endif

        <label>
            Accomplished Laundry Form
            <small>PDF, PNG, JPG, JPEG, or WebP · clear and readable</small>
            <input
                type="file"
                name="evidence"
                accept="application/pdf,image/png,image/jpeg,image/webp"
                required
            >
        </label>

        <button class="button primary ui-pressable">
            <x-icon name="upload" />
            Upload Form & Mark Ready for Pickup
        </button>

        <p class="meta">
            You do not need to encode the handwritten details in the system.
            SPMU will read and encode the signed form during verification.
        </p>
    </form>
</section>
@endif

@if($job->status === 'READY_FOR_PICKUP')
<section class="content-area narrow">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Laundry complete</p>
                <h2>Waiting for borrower pickup</h2>
            </div>
            <x-status-badge status="READY_FOR_PICKUP" />
        </div>

        <div class="callout success">
            <strong>The borrower has been notified.</strong>
            <p>
                Keep the cleaned linen until the borrower comes to collect it.
            </p>
        </div>

        <form
            method="post"
            action="{{ route('laundry.release-to-borrower', $job) }}"
            data-confirm-message="Release the cleaned linen to the borrower? This will tell SPMU that the linen is now being brought back for final inspection."
        >
            @csrf
            <button class="button primary ui-pressable">
                Release Clean Linen to Borrower
            </button>
        </form>
    </article>
</section>
@endif

@if($job->status === 'FOR_SPMU_FINAL_CHECK')
<section class="content-area narrow">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Laundry work complete</p>
                <h2>Borrower will return the linen to SPMU</h2>
            </div>
            <x-status-badge status="FOR_SPMU_FINAL_CHECK" />
        </div>

        <p>
            No more system action is required from Laundry for this transaction.
            SPMU will perform the final quantity and condition inspection.
        </p>
    </article>
</section>
@endif

@if($job->latestEvidence)
<section class="content-area narrow">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Latest upload</p>
                <h2>Accomplished form scan</h2>
            </div>
            <x-status-badge :status="$job->latestEvidence->verification_status" />
        </div>

        <a
            class="button secondary small ui-pressable"
            href="{{ route('files.show', $job->latestEvidence->file, false) }}"
            target="_blank"
            rel="noopener"
        >
            View Uploaded Scan
        </a>

        @if($job->latestEvidence->rejection_reason)
            <div class="callout warning top-gap">
                <strong>SPMU remark</strong>
                <p>{{ $job->latestEvidence->rejection_reason }}</p>
            </div>
        @endif
    </article>
</section>
@endif
@endsection
