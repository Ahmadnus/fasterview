@extends('layout')
@section('title', 'Occupancy')

@section('content')

    <div class="panel">
        <form method="GET" action="{{ route('occupancy.index') }}" class="filters">
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
                <label for="ownership">Ownership</label>
                <select name="ownership" id="ownership" onchange="this.form.submit()">
                    <option value="">Faster + Lent</option>
                    <option value="faster" @selected($ownership === 'faster')>Faster only (owned)</option>
                    <option value="lent" @selected($ownership === 'lent')>Lent only</option>
                </select>
            </div>

            <div>
                <label for="days">Date range</label>
                <select name="days" id="days" onchange="this.form.submit()">
                    @foreach ($ranges as $r)
                        <option value="{{ $r }}" @selected($days === $r)>Last {{ $r }} days</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label>&nbsp;</label>
                <input type="text" id="search" placeholder="Search car name or plate…" style="min-width:220px">
            </div>

            <div class="spacer" style="margin-left:auto"></div>
        </form>

        <div style="display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap">
            {{-- Pull Now: fast path skips the Power BI refresh (~20s). --}}
            <form method="POST" action="{{ route('occupancy.sync') }}" style="margin:0">
                @csrf
                <input type="hidden" name="category" value="{{ $category }}">
                <input type="hidden" name="days" value="{{ $days }}">
                <input type="hidden" name="ownership" value="{{ $ownership }}">
                <button>Pull Now</button>
            </form>

            <form method="POST" action="{{ route('occupancy.sync') }}" style="margin:0"
                  onsubmit="return confirm('A full Power BI refresh can take several minutes. Keep this tab open. Continue?')">
                @csrf
                <input type="hidden" name="full" value="1">
                <input type="hidden" name="category" value="{{ $category }}">
                <input type="hidden" name="days" value="{{ $days }}">
                <input type="hidden" name="ownership" value="{{ $ownership }}">
                <button class="secondary">Full Power BI refresh…</button>
            </form>

            <span class="small muted">
                “Pull Now” reads the dataset as it stands. “Full refresh” triggers a Power BI
                refresh first and waits for it.
                @if ($lastSync)
                    · Last sync: {{ $lastSync->started_at?->format('d M Y H:i') }}
                    ({{ $lastSync->status }}{{ $lastSync->rows_pulled ? ', '.$lastSync->rows_pulled.' rows' : '' }})
                @endif
            </span>
        </div>

        @if ($lastSync?->warnings)
            <div class="small muted" style="margin-top:8px">
                <strong>Last sync warnings:</strong> {!! nl2br(e($lastSync->warnings)) !!}
            </div>
        @endif
    </div>

    <div class="panel">
        <p class="small muted" style="margin-top:0">
            Showing the latest reading per car per category between
            <strong>{{ $from->format('d M Y') }}</strong> and <strong>{{ $to->format('d M Y') }}</strong>.
            Views are the sum of daily page views across that same range
            ({{ number_format($rows->sum('views')) }} total).
            Click any column heading to sort. {{ $rows->count() }} rows.
        </p>

        <div class="table-wrap">
            <table id="grid">
                <thead>
                <tr>
                    <th data-type="text">Car <span class="arrow"></span></th>
                    <th data-type="text">Plate <span class="arrow"></span></th>
                    <th data-type="text">Owner <span class="arrow"></span></th>
                    <th data-type="text">Category <span class="arrow"></span></th>
                    <th data-type="num" class="num">Occupancy % <span class="arrow"></span></th>
                    <th data-type="num" class="num">Views <span class="arrow"></span></th>
                    <th data-type="num" class="num">Rank <span class="arrow"></span></th>
                    <th data-type="num" class="num">Price <span class="arrow"></span></th>
                    <th data-type="text">Last pulled <span class="arrow"></span></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    @php
                        $occ = $row->occupancy_pct === null ? null : (float) $row->occupancy_pct;
                        $occClass = $occ === null ? '' : ($occ > 70 ? 'occ-high' : ($occ < 30 ? 'occ-low' : ''));
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('occupancy.show', ['serial' => $row->car_serial, 'days' => $days]) }}">
                                {{ $row->car_name ?: '(unnamed)' }}
                            </a>
                            <span class="small muted">#{{ $row->car_serial }}</span>
                        </td>
                        <td class="plate">{{ $row->plate ?: '—' }}</td>
                        <td>
                            @if ($row->ownership === 'faster')
                                <span class="tag tag-faster">Faster</span>
                            @elseif ($row->ownership === 'lent')
                                <span class="tag tag-lent">Lent</span>
                            @else
                                <span class="muted small" title="No matched OM car serial, so ownership is unknown">—</span>
                            @endif
                        </td>
                        <td>{{ $row->category ?: '—' }}</td>
                        <td class="num {{ $occClass }}" data-sort="{{ $occ ?? -1 }}">
                            {{ $occ === null ? '—' : number_format($occ, 2).'%' }}
                        </td>
                        <td class="num" data-sort="{{ $row->views }}">{{ number_format($row->views) }}</td>
                        <td class="num" data-sort="{{ $row->rank_position ?? 999999 }}">
                            {{ $row->rank_position ?? '—' }}
                        </td>
                        <td class="num" data-sort="{{ $row->price ?? -1 }}">
                            {{ $row->price === null ? '—' : number_format((float) $row->price) }}
                        </td>
                        <td data-sort="{{ $row->snapshot_datetime }}">
                            {{ \Illuminate\Support\Carbon::parse($row->snapshot_datetime)->format('d M Y H:i') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="muted" style="padding:24px;text-align:center">
                            No snapshots in this range yet — hit “Pull Now” to take the first one.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <p class="small muted">
            Green = occupancy above 70%, red = below 30%. Views are intentionally not
            colour-coded: the raw count is not comparable across categories.
            <br>
            <strong>Faster</strong> = we own the car (it carries an asset account in the books).
            <strong>Lent</strong> = taken on lease/contract. A dash means the car has no matched
            OM serial, so ownership is genuinely unknown rather than lent.
        </p>
    </div>

@endsection

@push('scripts')
<script>
    // Click-to-sort. Numeric columns sort on the data-sort attribute so that
    // "—" and formatted numbers still order correctly.
    (function () {
        const table = document.getElementById('grid');
        if (!table) return;

        const tbody = table.tBodies[0];
        let sortCol = -1, asc = false;

        table.querySelectorAll('th').forEach(function (th, index) {
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

        // Free-text filter on the car column.
        const search = document.getElementById('search');
        if (search) {
            search.addEventListener('input', function () {
                const needle = this.value.toLowerCase();
                tbody.querySelectorAll('tr').forEach(function (row) {
                    if (row.cells.length < 2) return;
                    // Match on car name (col 0) or plate (col 1).
                    const hay = (row.cells[0].textContent + ' ' + row.cells[1].textContent).toLowerCase();
                    row.style.display = hay.includes(needle) ? '' : 'none';
                });
            });
        }
    })();
</script>
@endpush
