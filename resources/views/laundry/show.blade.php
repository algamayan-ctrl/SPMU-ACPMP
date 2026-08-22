{{-- LAUNDRY DETAIL UI V1.3 20260822 --}}
@extends('layouts.app', ['title' => 'Laundry '.$job->custody->custody_no])

@section('content')
@php
    $hasReceiveAction = \Illuminate\Support\Facades\Route::has('laundry.confirm-received');
    $isFinishedForLaundry = in_array(
        $job->status,
        ['FOR_SPMU_FINAL_CHECK', 'LAUNDRY_COMPLETED'],
        true
    );
    $returnTab = $isFinishedForLaundry ? 'completed' : 'needs-action';

    $canUploadForm = in_array(
        $job->status,
        ['RECEIVED_IN_LAUNDRY', 'FORM_REPLACEMENT_REQUIRED'],
        true
    ) || (! $hasReceiveAction && $job->status === 'FOR_LAUNDRY');

    $currentStep = match ($job->status) {
        'FOR_LAUNDRY' => $hasReceiveAction ? 1 : 2,
        'RECEIVED_IN_LAUNDRY', 'FORM_REPLACEMENT_REQUIRED' => 2,
        'READY_FOR_PICKUP' => 3,
        'FOR_SPMU_FINAL_CHECK', 'LAUNDRY_COMPLETED' => 4,
        default => 1,
    };

    $stepLabels = [
        1 => 'Receive linen',
        2 => 'Upload form',
        3 => 'Borrower pickup',
        4 => 'SPMU final check',
    ];
@endphp

<style>
    .laundry-detail {
        --ld-navy: #071f3f;
        --ld-blue: #1769e0;
        --ld-blue-dark: #0c54bb;
        --ld-blue-soft: #eaf3ff;
        --ld-surface: #ffffff;
        --ld-subtle: #f6f9fd;
        --ld-border: #d1deec;
        --ld-text: #1b3553;
        --ld-muted: #647996;
        --ld-success: #17865f;
        display: grid;
        gap: 18px;
        max-width: 1180px;
        margin: 0 auto;
    }

    .laundry-back-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        width: fit-content;
        color: #31577d;
        text-decoration: none;
        font-size: .78rem;
        font-weight: 750;
    }

    .laundry-back-link:hover {
        color: var(--ld-blue-dark);
        text-decoration: none;
    }

    .laundry-case-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 24px;
        margin: 0;
    }

    .laundry-case-heading h1 {
        margin: 4px 0 5px;
        color: var(--ld-navy);
        font-size: clamp(1.65rem, 2.5vw, 2.1rem);
        letter-spacing: -.025em;
    }

    .laundry-case-heading p:last-child {
        margin: 0;
        color: var(--ld-muted);
    }

    .laundry-case-heading p strong {
        color: #344e6b;
    }

    .laundry-progress-card {
        padding: 18px 20px;
        border: 1px solid var(--ld-border);
        border-radius: 14px;
        background: var(--ld-surface);
        box-shadow: 0 2px 6px rgba(13, 48, 86, .04);
    }

    .laundry-progress-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 15px;
    }

    .laundry-progress-title strong {
        color: var(--ld-navy);
        font-size: .83rem;
    }

    .laundry-progress-title span {
        color: var(--ld-muted);
        font-size: .72rem;
    }

    .laundry-progress {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .laundry-step {
        position: relative;
        display: grid;
        justify-items: center;
        gap: 7px;
        color: #8090a5;
        text-align: center;
        font-size: .72rem;
        font-weight: 750;
    }

    .laundry-step::before {
        content: '';
        position: absolute;
        z-index: 0;
        top: 14px;
        right: 50%;
        left: -50%;
        height: 2px;
        background: #dce5ef;
    }

    .laundry-step:first-child::before {
        display: none;
    }

    .laundry-step-dot {
        position: relative;
        z-index: 1;
        display: grid;
        width: 29px;
        height: 29px;
        place-items: center;
        border: 2px solid #cfdae6;
        border-radius: 50%;
        background: #fff;
        color: #72849a;
        font-size: .72rem;
        font-weight: 850;
    }

    .laundry-step.complete,
    .laundry-step.active {
        color: var(--ld-navy);
    }

    .laundry-step.complete::before,
    .laundry-step.active::before {
        background: var(--ld-blue);
    }

    .laundry-step.complete .laundry-step-dot {
        border-color: var(--ld-blue);
        color: #fff;
        background: var(--ld-blue);
    }

    .laundry-step.active .laundry-step-dot {
        border-color: var(--ld-blue);
        color: var(--ld-blue);
        background: var(--ld-blue-soft);
        box-shadow: 0 0 0 4px rgba(23, 105, 224, .11);
    }

    .laundry-detail-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(310px, .8fr);
        gap: 18px;
        align-items: start;
    }

    .laundry-panel {
        overflow: hidden;
        border: 1px solid var(--ld-border);
        border-radius: 14px;
        background: var(--ld-surface);
        box-shadow: 0 2px 6px rgba(13, 48, 86, .04);
    }

    .laundry-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--ld-border);
        background: #fbfdff;
    }

    .laundry-panel-header h2 {
        margin: 4px 0 0;
        color: var(--ld-navy);
        font-size: 1.08rem;
    }

    .laundry-panel-body {
        padding: 20px;
    }

    .laundry-action-intro {
        margin: 0 0 17px;
        color: var(--ld-text);
        line-height: 1.6;
    }

    .laundry-callout {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 12px;
        margin-bottom: 17px;
        padding: 14px;
        border: 1px solid #b9d6f8;
        border-radius: 11px;
        background: #eef6ff;
    }

    .laundry-callout.warning {
        border-color: #edcf8c;
        background: #fff9ec;
    }

    .laundry-callout.success {
        border-color: #b6decf;
        background: #effaf5;
    }

    .laundry-callout-icon {
        display: grid;
        width: 34px;
        height: 34px;
        place-items: center;
        border-radius: 9px;
        color: var(--ld-blue-dark);
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(23, 105, 224, .16);
    }

    .laundry-callout.warning .laundry-callout-icon {
        color: #8b5a00;
    }

    .laundry-callout.success .laundry-callout-icon {
        color: var(--ld-success);
    }

    .laundry-callout strong {
        display: block;
        color: var(--ld-navy);
        font-size: .84rem;
    }

    .laundry-callout p {
        margin: 3px 0 0;
        color: #506982;
        font-size: .78rem;
        line-height: 1.5;
    }

    .laundry-upload-label {
        display: block;
        margin-bottom: 8px;
        color: var(--ld-navy);
        font-size: .78rem;
        font-weight: 800;
    }

    .laundry-dropzone {
        position: relative;
        display: grid;
        justify-items: center;
        gap: 5px;
        min-height: 164px;
        padding: 25px 18px;
        border: 1.5px dashed #8fb5e4;
        border-radius: 12px;
        background: #f7fbff;
        text-align: center;
        transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }

    .laundry-dropzone:hover,
    .laundry-dropzone:focus-within {
        border-color: var(--ld-blue);
        background: #f0f7ff;
        box-shadow: 0 0 0 3px rgba(23, 105, 224, .09);
    }

    .laundry-file-input {
        position: absolute;
        inset: 0;
        z-index: 2;
        width: 100%;
        height: 100%;
        margin: 0;
        opacity: 0;
        cursor: pointer;
    }

    .laundry-dropzone-icon {
        display: grid;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 11px;
        color: var(--ld-blue);
        background: #fff;
        box-shadow: 0 3px 10px rgba(18, 83, 165, .1);
    }

    .laundry-dropzone strong {
        color: var(--ld-navy);
        font-size: .87rem;
    }

    .laundry-dropzone small {
        color: var(--ld-muted);
        font-size: .72rem;
    }

    .laundry-file-name {
        max-width: 100%;
        margin-top: 4px;
        padding: 5px 10px;
        overflow: hidden;
        border-radius: 999px;
        background: #e6f1ff;
        color: #245783;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: .72rem;
        font-weight: 750;
    }

    .laundry-primary-action {
        width: 100%;
        min-height: 46px;
        margin-top: 14px;
        justify-content: center;
        border-color: var(--ld-blue);
        color: #fff;
        background: var(--ld-blue);
        box-shadow: 0 6px 14px rgba(23, 105, 224, .17);
    }

    .laundry-primary-action:hover {
        border-color: var(--ld-blue-dark);
        color: #fff;
        background: var(--ld-blue-dark);
    }

    .laundry-action-note {
        display: flex;
        align-items: flex-start;
        gap: 7px;
        margin: 12px 0 0;
        color: var(--ld-muted);
        font-size: .72rem;
        line-height: 1.5;
    }

    .laundry-resource-section {
        padding: 18px 19px;
        border-bottom: 1px solid var(--ld-border);
    }

    .laundry-resource-section:last-child {
        border-bottom: 0;
    }

    .laundry-resource-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .laundry-resource-heading h3 {
        margin: 0;
        color: var(--ld-navy);
        font-size: .92rem;
    }

    .laundry-resource-heading span {
        padding: 3px 8px;
        border-radius: 999px;
        color: #4a627c;
        background: #edf3f8;
        font-size: .66rem;
        font-weight: 800;
    }

    .laundry-resource-copy {
        margin: 0 0 13px;
        color: var(--ld-muted);
        font-size: .76rem;
        line-height: 1.55;
    }

    .laundry-resource-button {
        width: 100%;
        justify-content: center;
    }

    .laundry-items-list {
        display: grid;
        gap: 8px;
    }

    .laundry-item-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 11px 12px;
        border: 1px solid #dce6f0;
        border-radius: 10px;
        background: var(--ld-subtle);
    }

    .laundry-item-row strong {
        min-width: 0;
        color: var(--ld-navy);
        font-size: .78rem;
        line-height: 1.35;
    }

    .laundry-item-quantity {
        flex: 0 0 auto;
        padding: 5px 8px;
        border-radius: 8px;
        color: #315b83;
        background: #e8f2fd;
        font-size: .69rem;
        font-weight: 800;
    }

    .laundry-evidence-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: var(--ld-blue-dark);
        text-decoration: none;
        font-size: .76rem;
        font-weight: 800;
    }

    .laundry-evidence-link:hover {
        text-decoration: underline;
    }

    .laundry-remark {
        margin-top: 12px;
        padding: 11px 12px;
        border-left: 3px solid #d79a18;
        border-radius: 6px;
        background: #fff8e8;
    }

    .laundry-remark strong {
        display: block;
        color: #704800;
        font-size: .73rem;
    }

    .laundry-remark p {
        margin: 3px 0 0;
        color: #775a23;
        font-size: .73rem;
        line-height: 1.45;
    }

    @media (max-width: 940px) {
        .laundry-detail-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .laundry-case-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .laundry-progress-card {
            overflow-x: auto;
        }

        .laundry-progress {
            min-width: 560px;
        }

        .laundry-panel-header,
        .laundry-panel-body,
        .laundry-resource-section {
            padding: 16px;
        }
    }
</style>

<div class="laundry-detail">
    <a
        class="laundry-back-link"
        href="{{ route('laundry.index', ['tab' => $returnTab]) }}"
    >
        &larr; Back to Laundry Transactions
    </a>

    <section class="laundry-case-heading">
        <div>
            <p class="eyebrow">Laundry transaction</p>
            <h1>{{ $job->custody->custody_no }}</h1>
            <p>
                Borrower: <strong>{{ $job->custody->borrower->full_name }}</strong>
                &middot; Request {{ $job->custody->request->request_no }}
                &middot; {{ $job->lines->count() }} {{ $job->lines->count() === 1 ? 'item line' : 'item lines' }}
            </p>
        </div>

        <x-status-badge :status="$job->status" />
    </section>

    <section class="laundry-progress-card" aria-label="Laundry workflow progress">
        <div class="laundry-progress-title">
            <strong>Workflow progress</strong>
            <span>Current stage: {{ $stepLabels[$currentStep] }}</span>
        </div>

        <div class="laundry-progress">
            @foreach($stepLabels as $stepNumber => $stepLabel)
                @php
                    $stepClass = $stepNumber < $currentStep
                        || ($job->status === 'LAUNDRY_COMPLETED' && $stepNumber === 4)
                            ? 'complete'
                            : ($stepNumber === $currentStep ? 'active' : '');
                @endphp

                <div class="laundry-step {{ $stepClass }}">
                    <span class="laundry-step-dot">
                        {{ $stepNumber < $currentStep || ($job->status === 'LAUNDRY_COMPLETED' && $stepNumber === 4) ? 'OK' : $stepNumber }}
                    </span>
                    <span>{{ $stepLabel }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="laundry-detail-grid">
        <div class="laundry-panel">

            @if($hasReceiveAction && $job->status === 'FOR_LAUNDRY')
                <header class="laundry-panel-header">
                    <div>
                        <p class="eyebrow">Required action</p>
                        <h2>Receive used linen from borrower</h2>
                    </div>
                    <x-status-badge status="FOR_LAUNDRY" />
                </header>

                <div class="laundry-panel-body">
                    <div class="laundry-callout">
                        <span class="laundry-callout-icon" aria-hidden="true">i</span>
                        <div>
                            <strong>Confirm only after the physical handover.</strong>
                            <p>
                                Wait until the borrower brings the used linen and printed Laundry Form.
                                Do not confirm receipt while the items are still with the borrower.
                            </p>
                        </div>
                    </div>

                    <form
                        method="post"
                        action="{{ route('laundry.confirm-received', $job) }}"
                        data-confirm-message="Confirm that the borrower physically delivered the used linen and printed Laundry Form to Laundry?"
                    >
                        @csrf
                        <button class="button primary ui-pressable laundry-primary-action">
                            Confirm Used Linen Received
                        </button>
                    </form>

                    <p class="laundry-action-note">
                        <span aria-hidden="true">i</span>
                        This records the physical receipt date, Laundry Worker, and next workflow stage.
                    </p>
                </div>

            @elseif($canUploadForm)
                <header class="laundry-panel-header">
                    <div>
                        <p class="eyebrow">Required action</p>
                        <h2>
                            {{
                                $job->status === 'FORM_REPLACEMENT_REQUIRED'
                                    ? 'Replace accomplished Laundry Form'
                                    : 'Upload accomplished Laundry Form'
                            }}
                        </h2>
                    </div>
                    <x-status-badge :status="$job->status" />
                </header>

                <div class="laundry-panel-body">
                    @if($job->worker_received_at)
                        <div class="laundry-callout success">
                            <span class="laundry-callout-icon">
                                <x-icon name="success" size="18" />
                            </span>
                            <div>
                                <strong>Used linen received by Laundry.</strong>
                                <p>
                                    Received by {{ $job->worker_name ?: 'Laundry Worker' }}
                                    on {{ $job->worker_received_at->format('F j, Y g:i A') }}.
                                </p>
                            </div>
                        </div>
                    @endif

                    @if($job->status === 'FORM_REPLACEMENT_REQUIRED')
                        <div class="laundry-callout warning">
                            <span class="laundry-callout-icon" aria-hidden="true">!</span>
                            <div>
                                <strong>SPMU requested a replacement scan.</strong>
                                <p>Upload a clearer and fully readable copy of the accomplished form.</p>
                            </div>
                        </div>
                    @else
                        <p class="laundry-action-intro">
                            After washing and completing the physical form, upload one clear scan or photo.
                            SPMU will verify and encode the handwritten details.
                        </p>
                    @endif

                    <form
                        method="post"
                        action="{{ route('laundry.upload-form', $job) }}"
                        enctype="multipart/form-data"
                    >
                        @csrf

                        <span class="laundry-upload-label">Accomplished Laundry Form</span>

                        <label class="laundry-dropzone">
                            <input
                                class="laundry-file-input"
                                type="file"
                                name="evidence"
                                accept="application/pdf,image/png,image/jpeg,image/webp"
                                data-laundry-file-input
                                required
                            >

                            <span class="laundry-dropzone-icon" aria-hidden="true">
                                <x-icon name="upload" size="20" />
                            </span>

                            <strong>Choose a file or drag it here</strong>
                            <small>PDF, PNG, JPG, JPEG, or WebP &middot; clear and readable</small>
                            <span class="laundry-file-name" data-laundry-file-name>No file selected</span>
                        </label>

                        <button class="button primary ui-pressable laundry-primary-action">
                            <x-icon name="upload" size="17" />
                            {{ $job->status === 'FORM_REPLACEMENT_REQUIRED' ? 'Upload Replacement Form' : 'Upload Form and Mark Ready for Pickup' }}
                        </button>

                        <p class="laundry-action-note">
                            <span aria-hidden="true">i</span>
                            No computer encoding is required. Upload only the completed and signed physical form.
                        </p>
                    </form>
                </div>

            @elseif($job->status === 'READY_FOR_PICKUP')
                <header class="laundry-panel-header">
                    <div>
                        <p class="eyebrow">Required action</p>
                        <h2>Release cleaned linen to borrower</h2>
                    </div>
                    <x-status-badge status="READY_FOR_PICKUP" />
                </header>

                <div class="laundry-panel-body">
                    <div class="laundry-callout success">
                        <span class="laundry-callout-icon">
                            <x-icon name="success" size="18" />
                        </span>
                        <div>
                            <strong>The accomplished form was uploaded and the borrower was notified.</strong>
                            <p>
                                Keep the cleaned linen until the borrower physically collects it.
                                The borrower must bring it to SPMU afterward for final inspection.
                            </p>
                        </div>
                    </div>

                    <form
                        method="post"
                        action="{{ route('laundry.release-to-borrower', $job) }}"
                        data-confirm-message="Release the cleaned linen to the borrower? This will tell SPMU that the linen is being returned for final inspection."
                    >
                        @csrf
                        <button class="button primary ui-pressable laundry-primary-action">
                            Release Clean Linen to Borrower
                        </button>
                    </form>

                    <p class="laundry-action-note">
                        <span aria-hidden="true">i</span>
                        Click only while the physical items are being handed to the borrower.
                    </p>
                </div>

            @elseif(in_array($job->status, ['FOR_SPMU_FINAL_CHECK', 'LAUNDRY_COMPLETED'], true))
                <header class="laundry-panel-header">
                    <div>
                        <p class="eyebrow">Laundry work complete</p>
                        <h2>
                            {{
                                $job->status === 'LAUNDRY_COMPLETED'
                                    ? 'Transaction completed'
                                    : 'For final SPMU inspection'
                            }}
                        </h2>
                    </div>
                    <x-status-badge :status="$job->status" />
                </header>

                <div class="laundry-panel-body">
                    <div class="laundry-callout success">
                        <span class="laundry-callout-icon">
                            <x-icon name="success" size="18" />
                        </span>
                        <div>
                            <strong>No further Laundry action is required.</strong>
                            <p>
                                {{
                                    $job->status === 'LAUNDRY_COMPLETED'
                                        ? 'SPMU completed the final quantity, condition, and form verification.'
                                        : 'The borrower will bring the cleaned linen to SPMU for final quantity and condition inspection.'
                                }}
                            </p>
                        </div>
                    </div>

                    <a
                        class="button secondary ui-pressable laundry-resource-button"
                        href="{{ route('laundry.index', ['tab' => 'completed']) }}"
                    >
                        Return to Completed Transactions
                    </a>
                </div>

            @else
                <header class="laundry-panel-header">
                    <div>
                        <p class="eyebrow">Transaction status</p>
                        <h2>Review current transaction</h2>
                    </div>
                    <x-status-badge :status="$job->status" />
                </header>

                <div class="laundry-panel-body">
                    <p class="laundry-action-intro">
                        This transaction has no Laundry action available at the moment.
                        Review the status or contact SPMU if clarification is needed.
                    </p>
                </div>
            @endif
        </div>

        <aside class="laundry-panel">
            <section class="laundry-resource-section">
                <div class="laundry-resource-heading">
                    <h3>Physical Laundry Form</h3>
                    <span>{{ $job->document ? 'Available' : 'Unavailable' }}</span>
                </div>

                <p class="laundry-resource-copy">
                    Record received quantity, condition, stains or damage, completed quantity,
                    remarks, dates, name, and wet signature on the printed form.
                </p>

                @if($job->document)
                    <a
                        class="button secondary small ui-pressable laundry-resource-button"
                        href="{{ route('documents.download', $job->document) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View / Print Laundry Form
                    </a>
                @else
                    <div class="laundry-callout warning">
                        <span class="laundry-callout-icon" aria-hidden="true">!</span>
                        <div>
                            <strong>Form not available.</strong>
                            <p>Ask SPMU to regenerate the form before continuing.</p>
                        </div>
                    </div>
                @endif
            </section>

            <section class="laundry-resource-section">
                <div class="laundry-resource-heading">
                    <h3>Items covered</h3>
                    <span>{{ $job->lines->count() }} {{ $job->lines->count() === 1 ? 'line' : 'lines' }}</span>
                </div>

                <div class="laundry-items-list">
                    @foreach($job->lines as $line)
                        <div class="laundry-item-row">
                            <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                            <span class="laundry-item-quantity">
                                {{ $line->issued_quantity + 0 }}
                                {{ $line->custodyLine->requestItem->unit_snapshot }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>

            @if($job->latestEvidence)
                <section class="laundry-resource-section">
                    <div class="laundry-resource-heading">
                        <h3>Latest uploaded scan</h3>
                        <x-status-badge :status="$job->latestEvidence->verification_status" />
                    </div>

                    <a
                        class="laundry-evidence-link"
                        href="{{ route('files.show', $job->latestEvidence->file) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View accomplished form scan
                        <x-icon name="chevron-right" size="15" />
                    </a>

                    @if($job->latestEvidence->rejection_reason)
                        <div class="laundry-remark">
                            <strong>SPMU remark</strong>
                            <p>{{ $job->latestEvidence->rejection_reason }}</p>
                        </div>
                    @endif
                </section>
            @endif
        </aside>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-laundry-file-input]').forEach((input) => {
            input.addEventListener('change', () => {
                const fileName = input.files && input.files.length
                    ? input.files[0].name
                    : 'No file selected';
                const label = input.closest('.laundry-dropzone')
                    ?.querySelector('[data-laundry-file-name]');

                if (label) label.textContent = fileName;
            });
        });
    });
</script>
@endsection