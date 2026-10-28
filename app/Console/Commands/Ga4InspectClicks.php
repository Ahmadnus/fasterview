<?php

namespace App\Console\Commands;

use App\Services\PowerBiException;
use App\Services\PowerBiService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Exploratory command, in the same spirit as `powerbi:list-tables`: it answers
 * "what click data does this dataset actually have, and is it tied to a car?"
 * before any table or pull logic is written against it.
 *
 * It only reads. Nothing here writes to the database.
 */
class Ga4InspectClicks extends Command
{
    protected $signature = 'ga4:inspect-clicks
                            {--days=30 : How far back to sample}
                            {--all-events : List every event name, not just click-like ones}';

    protected $description = 'Show what GA4 click events exist and whether they identify a car';

    public function handle(): int
    {
        try {
            $pbi = PowerBiService::fromConfig();
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();

        $this->info("Sampling the last {$days} days.");

        try {
            $this->showEventNames($pbi, $from);
            $this->showGrain($pbi, $from);
            $this->showCarIdentifierSearch($pbi);
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Which events actually fire, and how often.
     */
    private function showEventNames(PowerBiService $pbi, CarbonImmutable $from): void
    {
        $dax = sprintf(<<<'DAX'
        EVALUATE
        TOPN(
            60,
            SUMMARIZE(
                FILTER('ga4_Events', 'ga4_Events'[_DATA_DATE] >= DATE(%d, %d, %d)),
                'ga4_Events'[eventName],
                "events", SUM('ga4_Events'[eventCount]),
                "days", DISTINCTCOUNT('ga4_Events'[_DATA_DATE])
            ),
            [events], DESC
        )
        DAX, $from->year, $from->month, $from->day);

        $rows = $pbi->executeDax($dax);

        $display = [];

        foreach ($rows as $row) {
            $name = (string) $this->val($row, 'eventName');

            if (! $this->option('all-events') && ! $this->looksLikeClick($name)) {
                continue;
            }

            $display[] = [
                $name,
                number_format((float) $this->val($row, 'events')),
                (int) $this->val($row, 'days'),
                $this->guessType($name) ?? '—',
            ];
        }

        $this->newLine();
        $this->info('Event names in ga4_Events'.($this->option('all-events') ? '' : ' (click-like only)'));

        if ($display === []) {
            $this->warn('No matching events found. Re-run with --all-events to see everything.');

            return;
        }

        $this->table(['eventName', 'Total events', 'Days seen', 'Would map to'], $display);
    }

    /**
     * What is one row of this table? If eventName + date is unique, the table
     * is site-wide per day and carries no per-car detail.
     */
    private function showGrain(PowerBiService $pbi, CarbonImmutable $from): void
    {
        $dax = sprintf(<<<'DAX'
        EVALUATE
        ROW(
            "rows", COUNTROWS(FILTER('ga4_Events', 'ga4_Events'[_DATA_DATE] >= DATE(%d, %d, %d))),
            "event_date_pairs", COUNTROWS(
                SUMMARIZE(
                    FILTER('ga4_Events', 'ga4_Events'[_DATA_DATE] >= DATE(%d, %d, %d)),
                    'ga4_Events'[eventName], 'ga4_Events'[_DATA_DATE]
                )
            ),
            "distinct_events", DISTINCTCOUNT('ga4_Events'[eventName]),
            "min_date", MIN('ga4_Events'[_DATA_DATE]),
            "max_date", MAX('ga4_Events'[_DATA_DATE])
        )
        DAX, $from->year, $from->month, $from->day, $from->year, $from->month, $from->day);

        $row = $pbi->executeDax($dax)[0] ?? [];

        $rows = (int) $this->val($row, 'rows');
        $pairs = (int) $this->val($row, 'event_date_pairs');

        $this->newLine();
        $this->info('Grain of ga4_Events');
        $this->table(['Metric', 'Value'], [
            ['Rows in window', number_format($rows)],
            ['Distinct (eventName, date) pairs', number_format($pairs)],
            ['Distinct event names', $this->val($row, 'distinct_events')],
            ['Full history from', substr((string) $this->val($row, 'min_date'), 0, 10)],
            ['Full history to', substr((string) $this->val($row, 'max_date'), 0, 10)],
            ['Grain verdict', $rows === $pairs
                ? 'ONE ROW PER EVENT PER DAY — site-wide, no car breakdown'
                : 'more rows than event/date pairs — some other dimension exists'],
        ]);
    }

    /**
     * The decisive question: does ANY table pair a click-ish column with a car
     * identifier? Scans the model's column list rather than guessing.
     */
    private function showCarIdentifierSearch(PowerBiService $pbi): void
    {
        $rows = $pbi->executeDax('EVALUATE INFO.VIEW.COLUMNS()');

        $carish = ['slug', 'carserial', 'car serial', 'carid', 'car id', 'siteid', 'site id', 'pagepath', 'pagetitle'];
        $clickish = ['click', 'whatsapp', 'call', 'event', 'cta', 'contact', 'lead', 'conversion'];

        $byTable = [];

        foreach ($rows as $row) {
            $table = (string) $this->val($row, 'Table');
            $column = (string) $this->val($row, 'Name');

            if ($table === '' || $column === '' || str_starts_with($column, 'RowNumber-')) {
                continue;
            }

            $lower = mb_strtolower($column);

            foreach ($carish as $needle) {
                if (str_contains($lower, $needle)) {
                    $byTable[$table]['car'][] = $column;
                }
            }

            foreach ($clickish as $needle) {
                if (str_contains($lower, $needle)) {
                    $byTable[$table]['click'][] = $column;
                }
            }
        }

        // A table can only give per-car clicks if it has both kinds of column.
        $both = [];
        $clickOnly = [];

        foreach ($byTable as $table => $hits) {
            $car = array_unique($hits['car'] ?? []);
            $click = array_unique($hits['click'] ?? []);

            if ($click === []) {
                continue;
            }

            $entry = [$table, implode(', ', array_slice($click, 0, 4)), implode(', ', array_slice($car, 0, 4)) ?: '—'];

            if ($car !== []) {
                $both[] = $entry;
            } else {
                $clickOnly[] = $entry;
            }
        }

        $this->newLine();
        $this->info('Tables with click-like columns AND a car identifier (usable per-car)');
        $this->table(['Table', 'Click-like columns', 'Car-identifying columns'], $both ?: [['— none —', '', '']]);

        $this->newLine();
        $this->info('Tables with click-like columns but NO car identifier (site-wide only)');
        $this->table(['Table', 'Click-like columns', 'Car-identifying columns'], $clickOnly ?: [['— none —', '', '']]);
    }

    private function looksLikeClick(string $name): bool
    {
        foreach (['click', 'whatsapp', 'call', 'select', 'contact', 'lead', 'cta', 'phone', 'book'] as $needle) {
            if (str_contains(mb_strtolower($name), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function guessType(string $name): ?string
    {
        $lower = mb_strtolower($name);

        return match (true) {
            str_contains($lower, 'whatsapp') => 'whatsapp',
            str_contains($lower, 'call') || str_contains($lower, 'phone') => 'call',
            str_contains($lower, 'click') || str_contains($lower, 'select') => 'general',
            default => null,
        };
    }

    /**
     * Power BI returns keys as "Table[Column]" or "[Alias]".
     */
    private function val(array $row, string $wanted): mixed
    {
        foreach ($row as $key => $value) {
            if (strcasecmp((string) preg_replace('/^.*\[|\]$/', '', $key), $wanted) === 0) {
                return $value;
            }
        }

        return null;
    }
}
