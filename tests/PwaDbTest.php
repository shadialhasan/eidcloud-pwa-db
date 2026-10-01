<?php

declare(strict_types=1);

namespace EidCloud\PwaDb\Tests;

use EidCloud\PwaDb\PwaDb;
use EidCloud\PwaDb\Schema\MigrationManager;
use EidCloud\PwaDb\Sdk\JsCompiler;
use EidCloud\PwaDb\Sync\SyncGateway;

class PwaDbTest
{
    private int $assertions = 0;

    public function runAll(): void
    {
        $this->testPwaDbFactory();
        $this->testSchemaValidationPass();
        $this->testSchemaValidationFail();
        $this->testMigrationManifest();
        $this->testSyncGatewayBatchSuccess();
        $this->testSyncGatewayConflictResolution();
        $this->testSyncGatewayJsonEndpoint();
        $this->testJsCompiler();
        $this->testJsCompilerOutputToDisk();

        echo "All tests passed successfully ({$this->assertions} assertions)!\n";
    }

    private function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: " . ($message ?: 'expected true, got false'));
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $msg = $message ?: ("Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
            throw new \RuntimeException("Assertion failed: " . $msg);
        }
    }

    public function testPwaDbFactory(): void
    {
        $this->assertEquals('1.0.0', PwaDb::VERSION);
        $this->assertTrue(PwaDb::createMigrationManager() instanceof MigrationManager);
        $this->assertTrue(PwaDb::createSyncGateway() instanceof SyncGateway);
        $this->assertTrue(PwaDb::createCompiler() instanceof JsCompiler);
    }

    public function testSchemaValidationPass(): void
    {
        $schema = [
            'name' => 'StoreDb',
            'version' => 1,
            'tables' => [
                'users' => [
                    'primaryKey' => 'id',
                    'autoIncrement' => false,
                    'indexes' => ['email']
                ]
            ]
        ];

        $manager = new MigrationManager($schema);
        $res = $manager->validate();

        $this->assertTrue($res['valid'], 'Valid schema should pass validation.');
        $this->assertEquals(0, count($res['errors']));
    }

    public function testSchemaValidationFail(): void
    {
        $invalidSchema = [
            'name' => '',
            'version' => 0,
            'tables' => []
        ];

        $manager = new MigrationManager($invalidSchema);
        $res = $manager->validate();

        $this->assertTrue(!$res['valid'], 'Invalid schema should fail validation.');
        $this->assertTrue(count($res['errors']) >= 3);
    }

    public function testMigrationManifest(): void
    {
        $schema = [
            'name' => 'StoreDb',
            'version' => 2,
            'tables' => [
                'products' => [
                    'primaryKey' => 'sku',
                    'autoIncrement' => false,
                    'indexes' => [
                        'category' => ['keyPath' => 'category', 'unique' => false]
                    ]
                ]
            ]
        ];

        $manager = new MigrationManager($schema);
        $manifest = $manager->getMigrationManifest();

        $this->assertEquals('StoreDb', $manifest['name']);
        $this->assertEquals(2, $manifest['version']);
        $this->assertTrue(isset($manifest['tables']['products']));
        $this->assertEquals('sku', $manifest['tables']['products']['primaryKey']);
    }

    public function testSyncGatewayBatchSuccess(): void
    {
        $schema = [
            'tables' => [
                'orders' => ['primaryKey' => 'id']
            ]
        ];

        $gateway = new SyncGateway($schema);
        $payload = [
            'transactions' => [
                [
                    'id' => 'tx-1',
                    'table' => 'orders',
                    'action' => 'insert',
                    'recordId' => 'ord-101',
                    'data' => ['id' => 'ord-101', 'total' => 99.95],
                    'timestamp' => time()
                ]
            ]
        ];

        $result = $gateway->processBatch($payload);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['appliedCount']);
        $this->assertEquals(0, $result['conflictsCount']);
        $this->assertEquals('ord-101', $result['applied'][0]['recordId']);
    }

    public function testSyncGatewayConflictResolution(): void
    {
        $gateway = new SyncGateway([], 'latest_timestamp');

        $now = time();
        $serverRecord = [
            'id' => 'prod-1',
            'title' => 'Server Title',
            'updated_at' => $now + 500 // Future / newer timestamp on server
        ];

        $payload = [
            'transactions' => [
                [
                    'id' => 'tx-conflict',
                    'table' => 'products',
                    'action' => 'update',
                    'recordId' => 'prod-1',
                    'data' => ['title' => 'Client Older Title'],
                    'timestamp' => $now // Older
                ]
            ]
        ];

        $result = $gateway->processBatch($payload, function ($table, $id) use ($serverRecord) {
            return $serverRecord;
        });

        $this->assertEquals(0, $result['appliedCount']);
        $this->assertEquals(1, $result['conflictsCount']);
        $this->assertEquals('tx-conflict', $result['conflicts'][0]['transactionId']);
    }

    public function testSyncGatewayJsonEndpoint(): void
    {
        $gateway = new SyncGateway();
        $payloadJson = json_encode([
            'transactions' => [
                [
                    'id' => 'tx-json-1',
                    'table' => 'notes',
                    'action' => 'insert',
                    'recordId' => 'note-1',
                    'data' => ['text' => 'Hello PWA'],
                    'timestamp' => time()
                ]
            ]
        ]);

        $responseJson = $gateway->handleJsonEndpoint($payloadJson);
        $data = json_decode($responseJson, true);

        $this->assertEquals('success', $data['status']);
        $this->assertEquals(1, $data['appliedCount']);
    }

    public function testJsCompiler(): void
    {
        $compiler = new JsCompiler([
            'name' => 'CustomPwaDb',
            'version' => 3,
            'tables' => [
                'tasks' => ['primaryKey' => 'id']
            ]
        ]);

        $js = $compiler->compile();

        $this->assertTrue(strpos($js, 'CustomPwaDb') !== false);
        $this->assertTrue(strpos($js, 'QueryBuilder') !== false);
        $this->assertTrue(strpos($js, 'Background Sync API') !== false);
    }

    public function testJsCompilerOutputToDisk(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_pwa_test_' . uniqid();
        $compiler = new JsCompiler();
        $savedFile = $compiler->saveTo($tempDir, 'client-db.js');

        $this->assertTrue(file_exists($savedFile));
        $this->assertTrue(filesize($savedFile) > 1000);

        // Cleanup
        unlink($savedFile);
        rmdir($tempDir);
    }
}
