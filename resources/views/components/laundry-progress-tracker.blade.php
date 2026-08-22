@props(['job'])
@php
    $status = (string) $job->status;
    $current = match($status) {
        'FOR_LAUNDRY' => 1,
        'IN_PROCESS' => 2,
        'READY_FOR_SPMU_RETURN' => 3,
        'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED' => 4,
        'LAUNDRY_COMPLETED' => 5,
        default => 1,
    };
    $steps = [
        1 => ['Borrower Turnover', 'Borrower signs the physical turnover portion and brings used linen + the printed Laundry Form to Laundry.'],
        2 => ['Laundry Processing', 'Laundry records actual receipt, processes the linen, completes quantities/condition, and signs the form.'],
        3 => ['Return to SPMU', 'Laundry Worker brings cleaned linen + the same physical form directly to SPMU for final inspection and signature.'],
        4 => ['Final Form Upload', 'After SPMU signs, the form is returned to Laundry for the final scan/upload.'],
        5 => ['Completed', 'Final signed form is archived and the Laundry transaction is settled.'],
    ];
@endphp

<article class="card laundry-progress-card" aria-label="Laundry progress">
    <div class="card-header">
        <div>
            <p class="eyebrow">Laundry tracker</p>
            <h2>Where this linen is now</h2>
        </div>
        <x-status-badge :status="$job->status" />
    </div>
    <ol class="laundry-progress-rail">
        @foreach($steps as $index => [$label, $description])
            @php
                $state = $index < $current
                    ? 'complete'
                    : ($index === $current ? 'current' : 'pending');
            @endphp
            <li class="laundry-progress-step is-{{ $state }}" @if($state === 'current') aria-current="step" @endif>
                <span class="laundry-progress-marker">{{ $state === 'complete' ? '✓' : $index }}</span>
                <div><strong>{{ $label }}</strong><small>{{ $description }}</small></div>
            </li>
        @endforeach
    </ol>
</article>
