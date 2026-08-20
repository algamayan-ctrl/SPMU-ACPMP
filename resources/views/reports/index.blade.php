@extends('layouts.app', ['title' => 'Reports & Analytics'])
@section('content')
@php
    $periodLabel = $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
    $requestMax = $requestStatuses->isNotEmpty() ? max($requestStatuses->max() ?? 0, 1) : 1;
    $custodyMax = $custodyStatuses->isNotEmpty() ? max($custodyStatuses->max() ?? 0, 1) : 1;
    $utilizationMax = $topItems->isNotEmpty() ? max($topItems->max('used_quantity') ?? 0, 1) : 1;
    $approvalHours = (int) floor($averageApprovalSeconds / 3600);
    $approvalMinutes = (int) floor(($averageApprovalSeconds % 3600) / 60);
    $approvalLabel = $averageApprovalSeconds ? ($approvalHours.'h '.$approvalMinutes.'m') : 'N/A';
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Business intelligence</p>
        <h1>Reports &amp; Analytics</h1>
    </div>
    <div class="actions report-header-actions">
        @if(auth()->user()->hasRole('SPMU') || auth()->user()->hasRole('ICTU'))
            <a class="button secondary ui-pressable" href="{{ route('reports.audit') }}"><x-icon name="reports" size="16" />Audit Trail</a>
            <a class="button secondary ui-pressable" href="{{ route('reports.notifications') }}"><x-icon name="notifications" size="16" />Delivery</a>
        @endif
        <button class="button primary ui-pressable" type="button" onclick="window.print()"><x-icon name="printer" size="16" />Print</button>
    </div>
</section>

<section class="content-area">
    <form method="get" class="card report-filter-card">
        <div class="report-filter-row">
            <label>
                <span>From</span>
                <input type="date" name="from" value="{{ $from->toDateString() }}">
            </label>
            <label>
                <span>To</span>
                <input type="date" name="to" value="{{ $to->toDateString() }}">
            </label>
            <button class="button primary ui-pressable" type="submit">Apply</button>
        </div>
        <p class="report-period-summary">Active period: {{ $periodLabel }}</p>
    </form>

    <div class="report-export-row top-gap">
        <details class="report-export-menu">
            <summary class="button secondary ui-pressable"><x-icon name="reports" size="16" />Export <x-icon name="chevron-down" size="16" /></summary>
            <div class="report-export-panel">
                @foreach(['inventory','borrowing','utilization','overdue','penalty','compliance'] as $type)
                    <a href="{{ route('reports.export', ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ ucfirst($type) }} CSV</a>
                @endforeach
                @if(auth()->user()->hasRole('SPMU') || auth()->user()->hasRole('ICTU'))
                    @foreach(['notification','audit'] as $type)
                        <a href="{{ route('reports.export', ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ ucfirst($type) }} CSV</a>
                    @endforeach
                @endif
            </div>
        </details>
    </div>
</section>

<section class="content-grid three report-kpi-grid">
    <article class="card dashboard-kpi-card kpi-accent-info">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="reports" size="18" /></span>
        <strong class="kpi-value">{{ number_format($auditCount) }}</strong>
        <span class="kpi-label">Audit events</span>
    </article>
    <article class="card dashboard-kpi-card kpi-accent-warning">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="notifications" size="18" /></span>
        <strong class="kpi-value">{{ number_format($failedNotifications) }}</strong>
        <span class="kpi-label">Failed deliveries</span>
    </article>
    <article class="card dashboard-kpi-card kpi-accent-success">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="inventory" size="18" /></span>
        <strong class="kpi-value">{{ number_format($items->count()) }}</strong>
        <span class="kpi-label">Tracked inventory</span>
    </article>
</section>

<section class="content-grid three report-kpi-grid">
    <article class="card dashboard-kpi-card kpi-accent-danger">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
        <strong class="kpi-value">{{ $overdueCount }} Overdue</strong>
        <span class="kpi-label">{{ $repeatOffenders }} Repeat borrowers</span>
    </article>
    <article class="card dashboard-kpi-card kpi-accent-warning">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="settings" size="18" /></span>
        <strong class="kpi-value">{{ (float) $penaltyTotal > 0 ? 'PHP '.number_format((float) $penaltyTotal, 2) : 'Amount not yet determined' }}</strong>
        <span class="kpi-label">Penalty value</span>
    </article>
    <article class="card dashboard-kpi-card kpi-accent-info">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="approval" size="18" /></span>
        <strong class="kpi-value">{{ $returnCompliance['released'] > 0 ? number_format($returnCompliance['percentage'], 1).'%' : 'N/A' }}</strong>
        <span class="kpi-label">Return compliance</span>
    </article>
</section>

<section class="content-grid two">
    <div class="card report-distribution-card">
        <div class="section-heading compact-section-heading">
            <div><p class="eyebrow">Request flow</p><h2>Request status distribution</h2></div>
        </div>
        @if($requestStatuses->isNotEmpty())
            <div class="distribution-list">
                @foreach($requestStatuses as $status => $total)
                    <div class="distribution-row">
                        <div class="distribution-row-head">
                            <span>{{ str($status)->replace('_', ' ')->title() }}</span>
                            <strong>{{ $total }}</strong>
                        </div>
                        <div class="distribution-bar-track"><span class="distribution-bar-fill" style="width: {{ (($total / $requestMax) * 100) }}%"></span></div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="empty-state-inline">No request activity for this period.</p>
        @endif
    </div>

    <div class="card report-distribution-card">
        <div class="section-heading compact-section-heading">
            <div><p class="eyebrow">Custody flow</p><h2>Custody status distribution</h2></div>
        </div>
        @if($custodyStatuses->isNotEmpty())
            <div class="distribution-list">
                @foreach($custodyStatuses as $status => $total)
                    <div class="distribution-row">
                        <div class="distribution-row-head">
                            <span>{{ str($status)->replace('_', ' ')->title() }}</span>
                            <strong>{{ $total }}</strong>
                        </div>
                        <div class="distribution-bar-track"><span class="distribution-bar-fill" style="width: {{ (($total / $custodyMax) * 100) }}%"></span></div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="empty-state-inline">No custody activity for this period.</p>
        @endif
    </div>
</section>

<section class="content-area">
    <div class="card report-table-card">
        <div class="section-heading compact-section-heading">
            <div><p class="eyebrow">Inventory snapshot</p><h2>Inventory state report</h2></div>
        </div>
        <div class="report-table-scroll">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th class="numeric">Total</th>
                        <th class="numeric">Available</th>
                        <th class="numeric">Allocated</th>
                        <th class="numeric">Borrowed</th>
                        <th class="numeric">Laundry</th>
                        <th class="numeric">Damaged / Maintenance</th>
                        <th class="numeric">Lost</th>
                        <th class="numeric">Stolen</th>
                        <th class="numeric">Destroyed</th>
                        <th class="numeric">Condemned</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        @php
                            $balance = $balances[$item->id];
                        @endphp
                        <tr>
                            <td>{{ $item->unique_description }}</td>
                            <td>{{ $item->category->category_name }}</td>
                            <td class="numeric {{ $balance['total'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['total'] + 0 }}</td>
                            <td class="numeric {{ $balance['available'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['available'] + 0 }}</td>
                            <td class="numeric {{ $balance['allocated'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['allocated'] + 0 }}</td>
                            <td class="numeric {{ $balance['borrowed'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['borrowed'] + 0 }}</td>
                            <td class="numeric {{ $balance['laundry'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['laundry'] + 0 }}</td>
                            <td class="numeric {{ $balance['damaged_maintenance'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['damaged_maintenance'] + 0 }}</td>
                            <td class="numeric {{ $balance['lost'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['lost'] + 0 }}</td>
                            <td class="numeric {{ $balance['stolen'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['stolen'] + 0 }}</td>
                            <td class="numeric {{ $balance['destroyed'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['destroyed'] + 0 }}</td>
                            <td class="numeric {{ $balance['condemned'] > 0 ? 'is-nonzero' : '' }}">{{ $balance['condemned'] + 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="content-area">
    <div class="card report-utilization-card">
        <div class="section-heading compact-section-heading">
            <div><p class="eyebrow">Usage intensity</p><h2>Utilization ranking</h2></div>
        </div>
        @if($topItems->isNotEmpty() && $topItems->sum('used_quantity') > 0)
            <div class="distribution-list">
                @foreach($topItems->take(10) as $item)
                    <div class="distribution-row">
                        <div class="distribution-row-head">
                            <span>{{ $item->unique_description }}</span>
                            <strong>{{ $item->used_quantity + 0 }} released</strong>
                        </div>
                        <div class="distribution-bar-track"><span class="distribution-bar-fill" style="width: {{ (($item->used_quantity / $utilizationMax) * 100) }}%"></span></div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="empty-state-inline">No utilization activity for this period.</p>
        @endif

        <details class="report-formula-panel">
            <summary>How these KPIs are calculated</summary>
            <div class="report-formula-body">
                <p><strong>Return compliance:</strong> closed released custody transactions ÷ released transactions × 100.</p>
                <p><strong>Average approval cycle:</strong> {{ $averageApprovalSeconds ? $approvalLabel : 'No completed cycle in period' }} average recorded duration.</p>
                <p><strong>Penalty value:</strong> total assessed amount within the selected reporting period.</p>
                <p><strong>Utilization:</strong> total released quantity per item in the selected period.</p>
            </div>
        </details>
    </div>
</section>
@endsection
