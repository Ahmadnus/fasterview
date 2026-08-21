<?php

namespace App\Console\Commands;

use App\Services\PowerBiException;
use App\Services\PowerBiService;
use Illuminate\Console\Command;

/**
 * Exploration helper: lists the tables and columns exposed by the dataset so we
 * can confirm the real names before writing the sync query against them.
 *
 * Nothing here writes to the database.
 */
class PowerBiListTables extends Command
{
    protected $signature = 'powerbi:list-tables
                            {--columns : Also list every column, grouped by table}
                            {--measures : Also list the dataset measures}
                            {--table= : Show columns for one table only (implies --columns)}
                            {--json : Dump raw JSON instead of formatted tables}';

    protected $description = 'List the tables, columns and measures available in the Power BI dataset';

    public function handle(): int
    {
        try {
            $pbi = PowerBiService::fromConfig();
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connecting to Power BI...');

        try {
            $this->components->task('Acquiring access token', function () use ($pbi) {
                $pbi->getAccessToken();

                return true;
            });

            $wantsColumns = $this->option('columns') || $this->option('table');

            $tables = $this->fetchTables($pbi);
            $this->renderTables($tables);

            if ($wantsColumns) {
                $this->renderColumns($this->fetchColumns($pbi));
            }

            if ($this->option('measures')) {
                $this->renderMeasures($this->fetchMeasures($pbi));
            }
        } catch (PowerBiException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Next step: confirm which table and columns hold the car serial, name,');
        $this->line('category, occupancy %, views, rank and price — then we finish powerbi:sync.');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Fetching
    // -----------------------------------------------------------------

    /**
     * INFO.TABLES() is the modern DMV wrapper. Older datasets may not expose it,
     * so fall back to the $SYSTEM DMV rowset.
     */
    private function fetchTables(PowerBiService $pbi): array
    {
        return $this->firstWorkingQuery($pbi, [
            'EVALUATE INFO.TABLES()',
            'EVALUATE INFO.VIEW.TABLES()',
            'EVALUATE SELECTCOLUMNS(INFO.TABLES(), "Name", [Name])',
        ], 'tables');
    }

    private function fetchColumns(PowerBiService $pbi): array
    {
        return $this->firstWorkingQuery($pbi, [
            'EVALUATE INFO.VIEW.COLUMNS()',
            'EVALUATE INFO.COLUMNS()',
        ], 'columns');
    }

    private function fetchMeasures(PowerBiService $pbi): array
    {
        return $this->firstWorkingQuery($pbi, [
            'EVALUATE INFO.VIEW.MEASURES()',
            'EVALUATE INFO.MEASURES()',
        ], 'measures');
    }

    /**
     * Try each DAX variant in turn and return the first that succeeds.
     */
    private function firstWorkingQuery(PowerBiService $pbi, array $queries, string $what): array
    {
        $lastError = null;

        foreach ($queries as $dax) {
            try {
                return $pbi->executeDax($dax);
            } catch (PowerBiException $e) {
                $lastError = $e;
                $this->line("  <fg=gray>· [{$dax}] not supported, trying next…</>");
            }
        }

        throw new PowerBiException(
            "Could not list {$what}. Last error: ".($lastError?->getMessage() ?? 'unknown')
        );
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    private function renderTables(array $rows): void
    {
        $this->newLine();
        $this->info('TABLES ('.count($rows).')');

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        // Hidden/system tables are noise when hunting for the fact table.
        $display = [];
        foreach ($rows as $row) {
            $name = $this->pick($row, ['Name', 'Table']);
            $hidden = $this->pick($row, ['IsHidden']);

            $display[] = [
                $name ?? '(unnamed)',
                $this->bool($hidden) ? 'hidden' : '',
                (string) ($this->pick($row, ['Description']) ?? ''),
            ];
        }

        $this->table(['Table', 'Flags', 'Description'], $display);
    }

    private function renderColumns(array $rows): void
    {
        if ($this->option('json')) {
            $this->newLine();
            $this->info('COLUMNS');
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $filter = $this->option('table');
        $grouped = [];

        foreach ($rows as $row) {
            $table = $this->pick($row, ['Table', 'TableName']) ?? '(unknown)';

            if ($filter && ! str_contains(mb_strtolower($table), mb_strtolower($filter))) {
                continue;
            }

            $grouped[$table][] = [
                $this->pick($row, ['Name', 'Column', 'ExplicitName']) ?? '(unnamed)',
                (string) ($this->pick($row, ['DataType', 'ExplicitDataType']) ?? ''),
                $this->bool($this->pick($row, ['IsHidden'])) ? 'hidden' : '',
            ];
        }

        if ($grouped === []) {
            $this->newLine();
            $this->warn($filter ? "No columns matched table filter [{$filter}]." : 'No columns returned.');

            return;
        }

        ksort($grouped);

        foreach ($grouped as $table => $cols) {
            $this->newLine();
            $this->info("COLUMNS — {$table} (".count($cols).')');
            $this->table(['Column', 'Type', 'Flags'], $cols);
        }
    }

    private function renderMeasures(array $rows): void
    {
        $this->newLine();
        $this->info('MEASURES ('.count($rows).')');

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $display = [];
        foreach ($rows as $row) {
            $display[] = [
                $this->pick($row, ['Table', 'TableName']) ?? '',
                $this->pick($row, ['Name', 'Measure']) ?? '(unnamed)',
                mb_substr((string) ($this->pick($row, ['Expression']) ?? ''), 0, 80),
            ];
        }

        $this->table(['Table', 'Measure', 'Expression (truncated)'], $display);
    }

    // -----------------------------------------------------------------
    // Row helpers — DMV column keys arrive as "[Name]" or "Table[Name]"
    // and vary between INFO.* and INFO.VIEW.* variants.
    // -----------------------------------------------------------------

    private function pick(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                $bare = trim((string) preg_replace('/^.*\[|\]$/', '', $key));

                if (strcasecmp($bare, $candidate) === 0 && $value !== null && $value !== '') {
                    return is_scalar($value) ? (string) $value : json_encode($value);
                }
            }
        }

        return null;
    }

    private function bool(?string $value): bool
    {
        return in_array(mb_strtolower((string) $value), ['1', 'true'], true);
    }
}
