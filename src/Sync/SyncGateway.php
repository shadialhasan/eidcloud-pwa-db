<?php

declare(strict_types=1);

namespace EidCloud\PwaDb\Sync;

use InvalidArgumentException;

/**
 * SyncGateway handles incoming PWA sync requests, parses delta mutations,
 * performs validation against schema definitions, and resolves conflicts.
 */
class SyncGateway
{
    /**
     * @var array<string, mixed>
     */
    private array $schema;

    /**
     * Conflict resolution strategy: 'client_wins', 'server_wins', 'latest_timestamp'
     */
    private string $conflictStrategy;

    /**
     * @param array<string, mixed> $schema
     * @param string $conflictStrategy
     */
    public function __construct(array $schema = [], string $conflictStrategy = 'latest_timestamp')
    {
        $this->schema = $schema;
        $this->conflictStrategy = $conflictStrategy;
    }

    /**
     * Process an incoming batch of offline transactions from the PWA client.
     *
     * @param array{
     *     clientId?: string,
     *     timestamp?: int|string,
     *     transactions: list<array{
     *         id: string,
     *         table: string,
     *         action: string,
     *         recordId: string|int,
     *         data?: array<string, mixed>,
     *         timestamp: int|string,
     *         version?: int
     *     }>
     * } $payload
     * @param callable|null $serverLookup Callback to fetch existing server record: fn(string $table, string|int $id): ?array
     * @return array{
     *     status: string,
     *     appliedCount: int,
     *     conflictsCount: int,
     *     applied: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     serverTime: string
     * }
     */
    public function processBatch(array $payload, ?callable $serverLookup = null): array
    {
        if (!isset($payload['transactions']) || !is_array($payload['transactions'])) {
            throw new InvalidArgumentException("Invalid sync payload: 'transactions' array is required.");
        }

        $applied = [];
        $conflicts = [];

        foreach ($payload['transactions'] as $tx) {
            $validationError = $this->validateTransaction($tx);
            if ($validationError !== null) {
                $conflicts[] = [
                    'transactionId' => $tx['id'] ?? null,
                    'error' => $validationError,
                    'action' => 'rejected',
                ];
                continue;
            }

            $table = (string)$tx['table'];
            $recordId = $tx['recordId'];
            $action = (string)$tx['action'];
            $data = $tx['data'] ?? [];
            $clientTime = is_numeric($tx['timestamp']) ? (int)$tx['timestamp'] : strtotime((string)$tx['timestamp']);

            $serverRecord = $serverLookup ? $serverLookup($table, $recordId) : null;

            if ($serverRecord !== null) {
                // Conflict resolution check
                $serverTime = isset($serverRecord['updated_at'])
                    ? (is_numeric($serverRecord['updated_at']) ? (int)$serverRecord['updated_at'] : strtotime((string)$serverRecord['updated_at']))
                    : 0;

                $resolution = $this->resolveConflict($tx, $serverRecord, $clientTime, $serverTime);

                if ($resolution['decision'] === 'server_wins') {
                    $conflicts[] = [
                        'transactionId' => $tx['id'],
                        'recordId' => $recordId,
                        'table' => $table,
                        'reason' => 'Server record has higher precedence or newer timestamp.',
                        'currentServerState' => $serverRecord,
                    ];
                    continue;
                }
            }

            $applied[] = [
                'transactionId' => $tx['id'],
                'table' => $table,
                'action' => $action,
                'recordId' => $recordId,
                'data' => $data,
                'syncedAt' => date('c'),
            ];
        }

        return [
            'status' => 'success',
            'appliedCount' => count($applied),
            'conflictsCount' => count($conflicts),
            'applied' => $applied,
            'conflicts' => $conflicts,
            'serverTime' => date('c'),
        ];
    }

    /**
     * @param array<string, mixed> $tx
     * @return string|null Null if valid, error message string if invalid
     */
    private function validateTransaction(array $tx): ?string
    {
        if (empty($tx['id']) || !is_string($tx['id'])) {
            return "Transaction must contain a string 'id'.";
        }
        if (empty($tx['table']) || !is_string($tx['table'])) {
            return "Transaction must contain a target 'table'.";
        }
        if (!isset($tx['recordId'])) {
            return "Transaction must contain 'recordId'.";
        }
        if (empty($tx['action']) || !in_array($tx['action'], ['insert', 'update', 'delete', 'upsert'], true)) {
            return "Transaction action must be one of: insert, update, delete, upsert.";
        }

        // Schema table validation if schema tables defined
        if (!empty($this->schema['tables']) && !isset($this->schema['tables'][$tx['table']])) {
            return "Table '{$tx['table']}' is not recognized in the database schema.";
        }

        return null;
    }

    /**
     * Resolve conflict between client mutation and server state.
     *
     * @param array<string, mixed> $clientTx
     * @param array<string, mixed> $serverRecord
     * @param int $clientTime
     * @param int $serverTime
     * @return array{decision: string, reason: string}
     */
    private function resolveConflict(array $clientTx, array $serverRecord, int $clientTime, int $serverTime): array
    {
        if ($this->conflictStrategy === 'client_wins') {
            return ['decision' => 'client_wins', 'reason' => 'Client wins strategy configured.'];
        }

        if ($this->conflictStrategy === 'server_wins') {
            return ['decision' => 'server_wins', 'reason' => 'Server wins strategy configured.'];
        }

        // Default: latest_timestamp
        if ($clientTime >= $serverTime) {
            return ['decision' => 'client_wins', 'reason' => 'Client update timestamp is newer or equal.'];
        }

        return ['decision' => 'server_wins', 'reason' => 'Server update timestamp is strictly newer.'];
    }

    /**
     * Helper to parse and return JSON response for an HTTP endpoint.
     *
     * @param string $jsonPayload
     * @param callable|null $serverLookup
     * @return string
     */
    public function handleJsonEndpoint(string $jsonPayload, ?callable $serverLookup = null): string
    {
        try {
            $data = json_decode($jsonPayload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new InvalidArgumentException("Request payload must be a JSON object.");
            }
            $result = $this->processBatch($data, $serverLookup);
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'serverTime' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    }
}
