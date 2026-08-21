@extends('layout')
@section('title', $carName ?: 'Car')

@section('content')

    <div class="panel">
        <a href="{{ route('occupancy.index', ['days' => $days]) }}" class="small">&larr; Back to all cars</a>

        <h2 style="margin:10px 0 4px">
            {{ $carName ?: '(unnamed)' }}
            <span class="muted">#{{ $serial }}</span>
            @if ($ownership === 'faster')
                <span class="tag tag-faster">Faster</span>
            @elseif ($ownership === 'lent')
                <span class="tag tag-lent">Lent</span>
            @endif
        </h2>

        @if ($plate)
            <p class="small muted" style="margin:0 0 4px">
                Plate: <span style="font-family:Consolas,monospace;letter-spacing:.04em">{{ $plate }}</span>
            </p>
        @endif

        <p class="small muted" style="margin-top:0">
            @if ($categories->isNotEmpty())
                Categories: {{ $categories->implode(', ') }} ·
            @endif
            Views over last {{ $days }} days: <strong>{{ number_format($viewsTotal) }}</strong>
        </p>

        <form method="GET" class="filters" style="margin-top:10px">
            <div>
                <label for="days">Date range</label>
                <select name="days" id="days" onchange="this.form.submit()">
                    @foreach ($ranges as $r)
                        <option value="{{ $r }}" @selected($days === $r)>Last {{ $r }} days</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Rentals over the last {{ $days }} days</h3>

        @if ($omSerial === null)
            <p class="muted small">
                This car has no matched OM serial, so its rental history cannot be linked.
            </p>
        @elseif ($daysOnFleet === 0)
            <p class="muted small">No fleet-status days recorded in this range.</p>
        @else
            <div style="display:flex;gap:26px;flex-wrap:wrap;margin-bottom:14px">
                <div>
                    <div class="small muted">Occupancy in range</div>
                    <div style="font-size:24px;font-weight:600">{{ number_format($rangeOccupancy, 1) }}%</div>
                </div>
                <div>
                    <div class="small muted">Rented / on fleet</div>
                    <div style="font-size:24px;font-weight:600">{{ $rentedDays }} <span class="muted" style="font-size:15px">/ {{ $daysOnFleet }} days</span></div>
                </div>
                <div>
                    <div class="small muted">Separate rentals</div>
                    <div style="font-size:24px;font-weight:600">{{ $spells }}</div>
                </div>
                <div>
                    <div class="small muted">Parked</div>
                    <div style="font-size:24px;font-weight:600">{{ $parkedDays }}</div>
                </div>
                <div>
                    <div class="small muted">Maintenance</div>
                    <div style="font-size:24px;font-weight:600">{{ $maintDays }}</div>
                </div>
                <div>
                    <div class="small muted">Revenue in range</div>
                    <div style="font-size:24px;font-weight:600">{{ number_format($rangeRevenue) }}</div>
                </div>
            </div>

            <div style="height:150px"><canvas id="statusChart"></canvas></div>

            <p class="small muted">
                Each bar is one day: <span class="tag tag-rented">rented</span>
                <span class="tag tag-maint">maintenance</span>
                <span class="tag tag-parked">parked</span>.
                A run of green bars is one rental. Occupancy here is computed from these
                days for the range you picked — not the undated figure from Power BI.
                “Separate rentals” counts unbroken green runs, so two back-to-back
                contracts read as one.
            </p>
        @endif
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Daily page views</h3>
        <p class="small muted" style="margin-top:0">
            Raw daily figures. If views climb while occupancy stays flat, the listing is
            attracting interest but not converting.
        </p>
        <div style="height:260px"><canvas id="viewChart"></canvas></div>
    </div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { y: { beginAtZero: true } }
    };

    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
        const codes = @json($statusValues);
        // 3 = rented, 2 = maintenance, 1 = parked, 0 = nothing recorded.
        const palette = { 3: '#2f9e57', 2: '#d08a1e', 1: '#b9c0c8', 0: '#eceff2' };
        const names = { 3: 'Rented', 2: 'Maintenance', 1: 'Parked', 0: '—' };
        const prices = @json($statusPrices);

        new Chart(statusCanvas, {
            type: 'bar',
            data: {
                labels: @json($statusLabels),
                datasets: [{
                    label: 'Status',
                    data: codes.map(() => 1),
                    backgroundColor: codes.map(c => palette[c]),
                    borderWidth: 0,
                    barPercentage: 1,
                    categoryPercentage: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const code = codes[ctx.dataIndex];
                                const price = prices[ctx.dataIndex];
                                return names[code] + (code === 3 && price ? ' · ' + price.toLocaleString() : '');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                    y: { display: false, max: 1 }
                }
            }
        });
    }

    new Chart(document.getElementById('viewChart'), {
        type: 'line',
        data: {
            labels: @json($viewLabels),
            datasets: [{
                label: 'Page views',
                data: @json($viewValues),
                borderColor: '#b06a12',
                backgroundColor: 'rgba(176,106,18,.12)',
                fill: true,
                tension: .25
            }]
        },
        options: baseOptions
    });
</script>
@endpush
