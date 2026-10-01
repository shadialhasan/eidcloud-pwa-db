<?php

declare(strict_types=1);

namespace EidCloud\PwaDb;

use EidCloud\PwaDb\Schema\MigrationManager;
use EidCloud\PwaDb\Sdk\JsCompiler;
use EidCloud\PwaDb\Sync\SyncGateway;

/**
 * Main entry point for EidCloud PWA DB library.
 */
class PwaDb
{
    public const VERSION = '1.0.0';

    /**
     * Create a new Schema Migration Manager instance.
     *
     * @param array<string, mixed> $schema
     * @return MigrationManager
     */
    public static function createMigrationManager(array $schema = []): MigrationManager
    {
        return new MigrationManager($schema);
    }

    /**
     * Create a new Sync Gateway instance.
     *
     * @param array<string, mixed> $schema
     * @param string $conflictStrategy
     * @return SyncGateway
     */
    public static function createSyncGateway(array $schema = [], string $conflictStrategy = 'latest_timestamp'): SyncGateway
    {
        return new SyncGateway($schema, $conflictStrategy);
    }

    /**
     * Create a new JavaScript SDK compiler.
     *
     * @param array<string, mixed>|null $schema
     * @return JsCompiler
     */
    public static function createCompiler(?array $schema = null): JsCompiler
    {
        return new JsCompiler($schema);
    }
}
