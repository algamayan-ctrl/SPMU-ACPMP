@extends('layouts.app', ['title' => 'Welcome'])

@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Supply and Property Management Unit</p>
        <h1>Borrow CSPC property with a clear, guided process.</h1>
        <p class="lead">Check available items, submit a request, track approval, receive your items, and return them through one secure system.</p>
        <div class="actions">
            <a class="button primary" href="{{ route('login') }}">Sign in</a>
            <a class="button secondary" href="#how-it-works">View borrowing process</a>
        </div>
    </div>
</section>
<section id="how-it-works" class="section-block">
    <p class="eyebrow">How it works</p>
    <h2>How borrowing works</h2>
    <p class="section-intro">Four simple stages from request to return.</p>
    <div class="feature-grid landing-workflow">
        <article class="workflow-step" data-step="01">
            <span class="step-number">01</span>
            <h3>Request</h3>
            <p>Check item availability and submit your borrowing request.</p>
        </article>
        <article class="workflow-step" data-step="02">
            <span class="step-number">02</span>
            <h3>Approval</h3>
            <p>Track your request through SPMU, GSU, and VPAF review.</p>
        </article>
        <article class="workflow-step" data-step="03">
            <span class="step-number">03</span>
            <h3>Release</h3>
            <p>Complete the required documents and receive the approved items.</p>
        </article>
        <article class="workflow-step" data-step="04">
            <span class="step-number">04</span>
            <h3>Return</h3>
            <p>Return the items for inspection and complete any remaining requirements.</p>
        </article>
    </div>
</section>
@endsection
