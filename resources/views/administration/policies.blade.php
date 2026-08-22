@extends('layouts.app', ['title' => 'Academic Period Configuration'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Head configuration</p>
        <h1>Academic Period Configuration</h1>
        <p>Maintain the active academic year and term used for reporting, violation history, and accountability records. Sanction decisions are handled per case in Accountability Oversight.</p>
    </div>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Academic calendar</p>
                <h2>Add / activate period</h2>
                <p class="meta">Only one academic period may be active at a time.</p>
            </div>
        </div>

        <form method="post" action="{{ route('policies.academic-periods.store') }}" class="form-grid">
            @csrf
            <div class="form-columns">
                <label>
                    Academic Year
                    <input name="academic_year" placeholder="2026-2027" value="{{ old('academic_year') }}" required>
                </label>
                <label>
                    Semester / Term
                    <select name="term_code" required>
                        <option value="FIRST_SEMESTER" @selected(old('term_code') === 'FIRST_SEMESTER')>1st Semester</option>
                        <option value="SECOND_SEMESTER" @selected(old('term_code') === 'SECOND_SEMESTER')>2nd Semester</option>
                        <option value="SUMMER_MIDYEAR" @selected(old('term_code') === 'SUMMER_MIDYEAR')>Summer / Midyear</option>
                    </select>
                </label>
                <label>
                    Term Name
                    <input name="term_name" value="{{ old('term_name') }}" placeholder="e.g. First Semester" required>
                </label>
                <label>
                    Start Date
                    <input type="date" name="start_date" value="{{ old('start_date') }}" required>
                </label>
                <label>
                    End Date
                    <input type="date" name="end_date" value="{{ old('end_date') }}" required>
                </label>
                <label>
                    Status
                    <select name="status" required>
                        <option value="UPCOMING" @selected(old('status', 'UPCOMING') === 'UPCOMING')>Upcoming</option>
                        <option value="ACTIVE" @selected(old('status') === 'ACTIVE')>Active</option>
                        <option value="COMPLETED" @selected(old('status') === 'COMPLETED')>Closed</option>
                    </select>
                </label>
            </div>
            <button class="button primary">Save Academic Period</button>
        </form>
    </article>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Periods</p>
                <h2>Configured Academic Periods</h2>
                <p class="meta">Historical records keep the academic period assigned when the transaction or violation was recorded.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Academic Year</th>
                        <th>Semester / Term</th>
                        <th>Dates</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($academicPeriods as $period)
                        <tr>
                            <td>{{ $period->academic_year }}</td>
                            <td>{{ $period->term_name }}</td>
                            <td>{{ $period->start_date->format('d M Y') }} – {{ $period->end_date->format('d M Y') }}</td>
                            <td>
                                <x-status-badge
                                    :status="$period->status"
                                    :label="$period->status === 'COMPLETED' ? 'Closed' : null"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No academic periods configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>
@endsection
