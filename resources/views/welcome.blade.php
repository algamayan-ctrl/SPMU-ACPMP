@extends('layouts.app', ['title' => 'Welcome'])

@section('content')
<section class="hero">
    <div><p class="eyebrow">Supply and Property Management Unit</p><h1>Borrow CSPC property with a clear, guided process.</h1><p class="lead">Check available items, submit a request, follow approvals, and complete release and return requirements in one secure system.</p><div class="actions"><a class="button primary" href="{{ route('login') }}">Sign in</a><a class="button secondary" href="#workflow">How it works</a></div></div>
    <aside class="hero-panel"><span class="panel-label">Approval sequence</span><ol><li><strong>SPMU</strong><span>Inventory and process review</span></li><li><strong>GSU</strong><span>Operational review</span></li><li><strong>VPAF</strong><span>Final approval and allocation</span></li></ol></aside>
</section>
<section id="workflow" class="section-block"><p class="eyebrow">Approved operational workflow</p><h2>Request → approval → download → release → return → closeout</h2><div class="feature-grid"><article><span>01</span><h3>Plan</h3><p>Check date-aware inventory and approved/active calendar commitments.</p></article><article><span>02</span><h3>Approve</h3><p>Capture exact version, action officer, signature, decision, and timestamps.</p></article><article><span>03</span><h3>Release</h3><p>Download the approved letter, view the protected slip, acknowledge, and physically release.</p></article><article><span>04</span><h3>Account</h3><p>Inspect returns and resolve laundry, evidence, incidents, penalties, or billing.</p></article></div></section>
@endsection
