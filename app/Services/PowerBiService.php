<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connection layer for the Power BI REST API.
 *
 * Deliberately free of business logic and DB writes so it can be reused by
 * commands, controllers and the future ranking features. Responsibilities:
 *
 *   1. Acquire an app-only token (MSAL client credentials flow).
 *   2. Trigger a dataset refresh.
 *   3. Poll refresh status until it settles.
 *   4. Run DAX via the executeQueries endpoint.
 */
class PowerBiService
{
    private ?string $token = null;

    /** Unix timestamp at which the cached token stops being usable. */
    private int $tokenExpiresAt = 0;

    public function __construct(private readonly array $config)
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'workspace_id', 'dataset_id'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                throw new PowerBiException("Missing Power BI config value [{$key}]. Check your .env file.");
            }
        }
    }

    public static function fromConfig(): self
    {
        return new self(config('powerbi'));
    }

    // ---------------------------------------------------------------------
    // 1. Authentication
    // ---------------------------------------------------------------------

    /**
     * Fetch (and cache in-process) an app-only bearer token.
     */
    public function getAccessToken(): string
    {
        // Re-use the token while it has more than a 60s safety margin left.
        if ($this->token !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->token;
        }

        $url = rtrim($this->config['authority'], '/').'/'.$this->config['tenant_id'].'/oauth2/v2.0/token';

        $response = Http::asForm()->timeout(30)->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'scope' => $this->config['scope'],
        ]);

        if ($response->failed()) {
            throw new PowerBiException(
                'Token request failed (HTTP '.$response->status().'): '.$this->briefly($response)
            );
        }

        $token = $response->json('access_token');

        if (! $token) {
            throw new PowerBiException('Token endpoint returned no access_token.');
        }

        $this->token = $token;
        $this->tokenExpiresAt = time() + (int) ($response->json('expires_in') ?? 3600);

        return $this->token;
    }

    // ---------------------------------------------------------------------
    // 2 & 3. Dataset refresh
    // ---------------------------------------------------------------------

    /**
     * Ask Power BI to start a dataset refresh. A 202 Accepted is the success case.
     */
    public function triggerRefresh(): void
    {
        $response = $this->request()->post($this->datasetUrl('refreshes'), [
            'notifyOption' => 'NoNotification',
        ]);

        if ($response->status() === 409) {
            throw new PowerBiException('A refresh is already running for this dataset (HTTP 409).');
        }

        if ($response->failed()) {
            throw new PowerBiException(
                'Could not start refresh (HTTP '.$response->status().'): '.$this->briefly($response)
            );
        }
    }

    /**
     * Most recent refresh entry for the dataset, or null if there is none.
     */
    public function latestRefresh(): ?array
    {
        $response = $this->request()->get($this->datasetUrl('refreshes'), ['$top' => 1]);

        if ($response->failed()) {
            throw new PowerBiException(
                'Could not read refresh history (HTTP '.$response->status().'): '.$this->briefly($response)
            );
        }

        return $response->json('value.0');
    }

    /**
     * Trigger a refresh and block until it settles.
     *
     * The baseline is captured BEFORE triggering so we can tell our refresh
     * apart from whatever ran previously — polling the newest entry alone would
     * happily report a stale "Completed" from an earlier run as our success.
     *
     * @param  callable|null  $onPoll  Receives (string $status, int $elapsedSeconds) after each check.
     * @return array The final refresh entry.
     */
    public function refreshAndWait(?callable $onPoll = null): array
    {
        $baseline = $this->latestRefresh();

        $this->triggerRefresh();

        return $this->waitForRefresh($onPoll, $baseline['startTime'] ?? null);
    }

    /**
     * Block until the running refresh reaches a terminal state.
     *
     * @param  callable|null  $onPoll  Receives (string $status, int $elapsedSeconds) after each check.
     * @param  string|null  $after  Only accept entries that started strictly after this
     *                              ISO timestamp. Anything older is a previous run.
     * @return array The final refresh entry.
     *
     * @throws PowerBiException on failure or timeout.
     */
    public function waitForRefresh(?callable $onPoll = null, ?string $after = null): array
    {
        $interval = max(5, (int) $this->config['poll_interval']);
        $timeout = max($interval, (int) $this->config['poll_timeout']);
        $start = time();

        while (true) {
            $entry = $this->latestRefresh();
            $status = $entry['status'] ?? 'Unknown';
            $elapsed = time() - $start;

            // Our refresh has not been registered yet — keep waiting rather
            // than reading a verdict off the previous run.
            if ($entry !== null && $after !== null && ($entry['startTime'] ?? '') <= $after) {
                $status = 'Queued';
                $entry = null;
            }

            if ($entry === null) {
                if ($onPoll) {
                    $onPoll($status, $elapsed);
                }

                if ($elapsed + $interval > $timeout) {
                    throw new PowerBiException(
                        "Timed out after {$timeout}s waiting for the refresh to start."
                    );
                }

                sleep($interval);

                continue;
            }

            if ($onPoll) {
                $onPoll($status, $elapsed);
            }

            if ($status === 'Completed') {
                return $entry;
            }

            if (in_array($status, ['Failed', 'Disabled', 'Cancelled'], true)) {
                $detail = $entry['serviceExceptionJson'] ?? json_encode($entry);
                throw new PowerBiException("Refresh finished with status [{$status}]: {$detail}");
            }

            if ($elapsed + $interval > $timeout) {
                throw new PowerBiException(
                    "Timed out after {$timeout}s waiting for the refresh to complete (last status: {$status})."
                );
            }

            sleep($interval);
        }
    }

    // ---------------------------------------------------------------------
    // 4. DAX
    // ---------------------------------------------------------------------

    /**
     * Execute a DAX query and return its first result table as an array of
     * associative rows. Power BI returns column keys in "Table[Column]" form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function executeDax(string $dax): array
    {
        $response = $this->request()->timeout(180)->post($this->datasetUrl('executeQueries'), [
            'queries' => [
                ['query' => $dax],
            ],
            'serializerSettings' => ['includeNulls' => true],
        ]);

        if ($response->failed()) {
            throw new PowerBiException(
                'DAX query failed (HTTP '.$response->status().'): '.$this->briefly($response)
            );
        }

        if ($error = $response->json('results.0.error')) {
            throw new PowerBiException('DAX query returned an error: '.json_encode($error));
        }

        return $response->json('results.0.tables.0.rows', []);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(60);
    }

    private function datasetUrl(string $suffix): string
    {
        return sprintf(
            '%s/groups/%s/datasets/%s/%s',
            rtrim($this->config['api_base'], '/'),
            $this->config['workspace_id'],
            $this->config['dataset_id'],
            $suffix
        );
    }

    /**
     * Trim an error body down to something readable for the console, and log
     * the fuller version for debugging.
     */
    private function briefly(Response $response): string
    {
        $body = trim($response->body());

        Log::warning('Power BI API error', [
            'status' => $response->status(),
            'body' => mb_substr($body, 0, 2000),
        ]);

        return mb_substr($body, 0, 500) ?: '(empty response body)';
    }
}
