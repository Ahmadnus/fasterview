@extends('layout')
@section('title', 'Changes')

@push('head')
<style>
    /* Metric groups get alternating tints so the eye can follow a pair of
       columns across a wide row. */
    .grp-a { background: #fbfcfd; }
    .grp-b { background: #f6f8fa; }
    thead tr.groups th { text-align: center; font-size: 11px; letter-spacing: .08em; }
    td.win  { background: var(--good); }
    td.loss { background: var(--bad); }
    td.pending { color: var(--muted); font-style: italic; }
    .tag-rank  { background: #e4ecf7; color: #14508c; }
    .tag-price { background: #f2e9dc; color: #8a5a12; }
    .ba { white-space: nowrap; }
    .ba .arrowto { color: var(--muted); padding: 0 3px; }
    .up { color: #2f7d4a; }
    .down { color: #a33; }
</style>
@endpush

@section('content')

    <div class="panel">
        <form method="GET" action="{{ route('changes.index') }}" class="filters">
            <div>
                <label for="category">Category</label>
                <select name="category" id="category" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" @selected($category === $cat)>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="type">Change type</label>
                <select name="type" id="type" onchange="this.form.submit()">
                    <option value="">Rank + Price</option>
                    <option value="rank" @selected($type === 'rank')>Rank only</option>
                    <option value="price" @selected($type === 'price')>Price only</option>
                </select>
            </div>

            <div>
                <label for="days">Changes from</label>
                <select name="days" id="days" onchange="this.form.submit()">
                    @foreach ($ranges as $r)
                        <option value="{{ $r }}" @selected($days === $r)>Last {{ $r }} days</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="window">Before / after window</label>
                <select name="window" id="window" onchange="this.form.submit()">
                    @foreach ($windows as $w)
                        <option value="{{ $w }}" @selected($window === $w)>± {{ $w }} days</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="ready">Results</label>
                <select name="ready" id="ready" onchange="this.form.submit()">
                    <option value="all" @selected($ready === 'all')>All changes</option>
                    <option value="1" @selected($ready === '1')>Only ones with results</option>
                    <option value="pending" @selected($ready === 'pending')>Only still in window</option>
                </select>
            </div>

            <div>
                <label>&nbsp;</label>
                <input type="text" id="search" placeholder="Search car name…" style="min-width:200px">
            </div>
        </form>
    </div>

    <div class="panel">
        <p class="small muted" style="margin-top:0">
            {{ $events->count() }} change(s)@if ($ready === '1') <strong>with results</strong>@elseif ($ready === 'pending') still inside their window @endif
            in the last {{ $days }} days.@if ($ready === '1')
                Changes newer than the window are hidden — switch <em>Results</em> to “All changes” to see them.
            @endif Each metric is summed
            over the <strong>{{ $window }} days before</strong> the change and the
            <strong>{{ $window }} days after</strong>, then compared against the average movement of the
            other cars in the same category over the identical window.
            <strong>The “vs category” column is the one that matters</strong> — a car rising while its
            whole category rises is not evidence the change worked.
        </p>

        <div class="table-wrap">
            <table id="grid">
                <thead>
                <tr class="groups">
                    <th colspan="5"></th>
                    <th colspan="2" class="grp-a">VIEWS</th>
                    <th colspan="2" class="grp-b">CONVERSIONS</th>
                    <th colspan="2" class="grp-a">OCCUPANCY</th>
                </tr>
                <tr>
                    <th data-type="text">Car <span class="arrow"></span></th>
                    <th data-type="text">Category <span class="arrow"></span></th>
                    <th data-type="text">Type <span class="arrow"></span></th>
                    <th data-type="num" class="num">Change <span class="arrow"></span></th>
                    <th data-type="text">Detected <span class="arrow"></span></th>

                    <th data-type="text" class="grp-a">Before → After <span class="arrow"></span></th>
                    <th data-type="num" class="num grp-a">vs category <span class="arrow"></span></th>

                    <th data-type="text" class="grp-b">Before → After <span class="arrow"></span></th>
                    <th data-type="num" class="num grp-b">vs category <span class="arrow"></span></th>

                    <th data-type="text" class="grp-a">Before → After <span class="arrow"></span></th>
                    <th data-type="num" class="num grp-a">vs category <span class="arrow"></span></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($events as $e)
                    @php
                        $isRank = $e->change_type === 'rank';
                        // Rank improves downward (1 beats 9); price is shown as-is.
                        $improved = $e->improved();
                        $delta = abs((float) $e->new_value - (float) $e->old_value);
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('occupancy.show', ['serial' => $e->car_serial, 'days' => 30]) }}">
                                {{ $e->car_name ?: '(unnamed)' }}
                            </a>
                            <span class="small muted">#{{ $e->car_serial }}</span>
                        </td>
                        <td>{{ $e->category ?: '—' }}</td>
                        <td>
                            <span class="tag {{ $isRank ? 'tag-rank' : 'tag-price' }}">
                                {{ $isRank ? 'Rank' : 'Price' }}
                            </span>
                        </td>
                        <td class="num ba" data-sort="{{ $improved === null ? 0 : ($improved ? $delta : -$delta) }}">
                            {{ $isRank ? (int) $e->old_value : number_format((float) $e->old_value) }}
                            <span class="arrowto">→</span>
                            <strong>{{ $isRank ? (int) $e->new_value : number_format((float) $e->new_value) }}</strong>
                            @if ($improved !== null)
                                <span class="{{ $improved ? 'up' : 'down' }}">
                                    {{ $improved ? '▲' : '▼' }}{{ $isRank ? (int) $delta : number_format($delta) }}
                                </span>
                            @endif
                        </td>
                        <td data-sort="{{ $e->detected_at }}">
                            {{ $e->detected_at?->format('d M Y') }}
                        </td>

                        @foreach ([['views', 'grp-a'], ['conversions', 'grp-b'], ['occupancy', 'grp-a']] as [$m, $grp])
                            @php
                                $before = $e->before[$m] ?? null;
                                $after  = $e->after[$m] ?? null;
                                $diff    = $e->{$m.'_diff'};
                                $verdict = $e->{$m.'_verdict'};
                                $isPct   = $m === 'occupancy';
                                $fmt = fn ($v) => $v === null
                                    ? '—'
                                    : ($isPct ? number_format($v, 1).'%' : number_format($v));
                            @endphp
                            <td class="ba {{ $grp }}">
                                @if (! $e->window_complete)
                                    <span class="pending">{{ $fmt($before) }} → …</span>
                                @else
                                    {{ $fmt($before) }}<span class="arrowto">→</span><strong>{{ $fmt($after) }}</strong>
                                @endif
                            </td>
                            <td class="num {{ $grp }} {{ $verdict === 'win' ? 'win' : ($verdict === 'loss' ? 'loss' : '') }}"
                                data-sort="{{ $diff ?? -99999 }}">
                                @if ($verdict === 'pending')
                                    <span class="pending" title="The after-window closes on {{ $e->after_ready_on?->format('d M Y') }}">
                                        in window
                                    </span>
                                @elseif ($diff === null)
                                    <span class="muted"
                                          title="{{ $m === 'conversions'
                                            ? 'Fewer than '.$minConversions.' conversions before the change — a percentage would be noise.'
                                            : 'Not enough data to compare.' }}">—</span>
                                @else
                                    {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 1) }}{{ $isPct ? ' pp' : '%' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="muted" style="padding:24px;text-align:center">
                            No changes detected in this range. Changes are found automatically on every
                            sync — they appear here once two snapshots differ.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <p class="small muted">
            <strong>Reading the colours.</strong> Green means the car beat its category by
            {{ $winThreshold }} or more; red means it trailed by {{ abs($lossThreshold) }} or more;
            no colour means the gap is too small to call. Views and conversions are compared as
            percentage growth, occupancy in percentage points (a move from 40% to 60% is +20 pp).
            Both thresholds live in <code>ChangeImpactAnalyser::WIN_THRESHOLD</code> /
            <code>LOSS_THRESHOLD</code>.
            <br>
            <strong>Why some conversion cells are blank.</strong> Conversions are sparse — most cars
            record none in a week. When a car had fewer than {{ $minConversions }} conversions before
            the change, no growth percentage is shown, because 0 → 1 is not a 100% improvement.
            <br>
            <strong>“in window”</strong> means the after-period has not finished yet, so no number is
            shown rather than a misleading partial one.
            <br>
            <strong>Rank</strong> counts down: a move from 24 to 9 is an improvement (▲15).
            Price changes are car-level, so they carry no category.
        </p>
    </div>

@endsection

@push('scripts')
<script>
    // Same click-to-sort as the occupancy grid: numeric columns sort on
    // data-sort so "—" and formatted numbers still order correctly.
    (function () {
        const table = document.getElementById('grid');
        if (!table) return;

        const tbody = table.tBodies[0];
        let sortCol = -1, asc = false;

        // Only the second header row carries sortable columns; the first row
        // is the metric grouping.
        table.querySelectorAll('thead tr:not(.groups) th').forEach(function (th, index) {
            th.addEventListener('click', function () {
                asc = sortCol === index ? !asc : (th.dataset.type === 'text');
                sortCol = index;

                const numeric = th.dataset.type === 'num';
                const rows = Array.from(tbody.querySelectorAll('tr'));
                if (rows.length && rows[0].cells.length < 2) return; // "no rows" placeholder

                rows.sort(function (a, b) {
                    const x = cellValue(a.cells[index], numeric);
                    const y = cellValue(b.cells[index], numeric);
                    if (x < y) return asc ? -1 : 1;
                    if (x > y) return asc ? 1 : -1;
                    return 0;
                });

                rows.forEach(r => tbody.appendChild(r));

                table.querySelectorAll('th .arrow').forEach(s => s.textContent = '');
                th.querySelector('.arrow').textContent = asc ? '▲' : '▼';
            });
        });

        function cellValue(cell, numeric) {
            const raw = cell.dataset.sort !== undefined ? cell.dataset.sort : cell.textContent.trim();
            return numeric ? parseFloat(raw) : raw.toLowerCase();
        }

        const search = document.getElementById('search');
        if (search) {
            search.addEventListener('input', function () {
                const needle = this.value.toLowerCase();
                tbody.querySelectorAll('tr').forEach(function (row) {
                    if (row.cells.length < 2) return;
                    // Match on car name (col 0) or category (col 1).
                    const hay = (row.cells[0].textContent + ' ' + row.cells[1].textContent).toLowerCase();
                    row.style.display = hay.includes(needle) ? '' : 'none';
                });
            });
        }
    })();
</script>
@endpush
