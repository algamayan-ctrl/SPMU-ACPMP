@extends('layouts.app', ['title' => 'Welcome'])

@section('content')
<section class="hero" aria-labelledby="landing-title">
    <div class="hero-content">
        <h1 id="landing-title">Supply and Property Management Unit</h1>
        <p class="hero-statement">Borrow CSPC property with a clear, guided process.</p>
        <p class="hero-description">Check availability, submit requests, track approvals, and manage returns in one secure system.</p>
        <div class="actions">
            <a class="button primary" href="{{ route('login') }}">Sign in</a>
            <a class="button secondary" href="#how-it-works">Learn more</a>
        </div>
    </div>
</section>

<section id="how-it-works" class="section-block">
    <p class="eyebrow">How borrowing works</p>
    <h2>How borrowing works</h2>
    <p class="section-intro">Four clear stages from request to return.</p>
    <div class="feature-grid landing-workflow">
        <article class="workflow-step" data-step="01">
            <span class="step-number">01</span>
            <h3>Request</h3>
            <p>Check item availability and submit a borrowing request for the approved use period.</p>
        </article>
        <article class="workflow-step" data-step="02">
            <span class="step-number">02</span>
            <h3>Approval</h3>
            <p>Track the request through SPMU verification, reservation, pickup, return, and accountability processing. Required GSU/VPAF institutional signatures are completed physically on the printed Borrowing Request Letter; SPMU verifies the uploaded signed scan.</p>
        </article>
        <article class="workflow-step" data-step="03">
            <span class="step-number">03</span>
            <h3>Release</h3>
            <p>Complete required documentation and receive the approved items from custody.</p>
        </article>
        <article class="workflow-step" data-step="04">
            <span class="step-number">04</span>
            <h3>Return</h3>
            <p>Return the items on schedule and complete the inspection and accountability process.</p>
        </article>
    </div>
</section>

<section class="section-block public-section">
    <p class="eyebrow">Borrowing tools</p>
    <h2>Built for accountable resource use</h2>
    <div class="capability-grid">
        <article class="capability-card">
            <h3>Item availability</h3>
            <p>View currently available property and check borrowing eligibility before requesting.</p>
        </article>
        <article class="capability-card">
            <h3>Request tracking</h3>
            <p>Monitor each request through approval and release status updates.</p>
        </article>
        <article class="capability-card">
            <h3>Borrowing calendar</h3>
            <p>Review schedules and understand active borrowing periods for planned use.</p>
        </article>
        <article class="capability-card">
            <h3>Release records</h3>
            <p>Confirm custody actions, conditions, and documentation for each approved release.</p>
        </article>
        <article class="capability-card">
            <h3>Return compliance</h3>
            <p>Track due dates, return requirements, and follow-up conditions after release.</p>
        </article>
        <article class="capability-card">
            <h3>Operational accountability</h3>
            <p>Maintain an auditable trail for institutional property custody and reporting.</p>
        </article>
    </div>
</section>

<section class="section-block info-grid-section">
    <div class="info-grid">
        <article class="info-card info-card--green">
            <div class="info-card__header">
                <span class="info-card__icon" aria-hidden="true"><x-icon name="success" size="16" /></span>
                <h2>Before you borrow</h2>
            </div>
            <ul>
                <li>Make sure your account information is current.</li>
                <li>Prepare the required supporting documents and obtain the required handwritten/wet signatures.</li>
                <li>Check item availability and required dates.</li>
                <li>Provide the correct purpose or event information.</li>
                <li>Return borrowed property within the approved schedule.</li>
            </ul>
        </article>
        <article class="info-card info-card--amber">
            <div class="info-card__header">
                <span class="info-card__icon" aria-hidden="true"><x-icon name="accountability" size="16" /></span>
                <h2>Accountability notice</h2>
            </div>
            <p class="accountability-copy">Borrowers are responsible for property released under their custody until the items are properly returned and acknowledged by SPMU.</p>
        </article>
    </div>
</section>

<section class="section-block cta-panel" aria-labelledby="cta-heading">
    <div class="cta-panel__icon" aria-hidden="true"><x-icon name="profile" size="22" /></div>
    <div class="cta-panel__content">
        <h2 id="cta-heading">Ready to submit a borrowing request?</h2>
        <p>Sign in using your authorized CSPC account to get started.</p>
    </div>
    <a class="button primary" href="{{ route('login') }}">Sign in</a>
</section>
@endsection
