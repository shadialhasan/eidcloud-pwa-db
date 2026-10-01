<?php

declare(strict_types=1);

namespace EidCloud\PwaDb\Schema;

use InvalidArgumentException;

/**
 * Validates, formats, and manages IndexedDB schema definitions and migration steps.
 */
class MigrationManager
{
    /**
     * @var array<string, mixed>
     */
    private array $schema;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(array $schema = [])
    {
        $this->schema = $schema;
    }

    /**
     * Load schema definition from a JSON file.
     *
     * @param string $filePath
     * @return self
     * @throws InvalidArgumentException
     */
    public static function fromJsonFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Schema file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new InvalidArgumentException("Unable to read schema file: {$filePath}");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON schema in: {$filePath}");
        }

        return new self($data);
    }

    /**
     * Validate the loaded schema structure.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->schema['name']) || !is_string($this->schema['name'])) {
            $errors[] = "Missing or invalid 'name' in schema.";
        }

        if (!isset($this->schema['version']) || !is_int($this->schema['version']) || $this->schema['version'] < 1) {
            $errors[] = "'version' must be an integer >= 1.";
        }

        if (!isset($this->schema['tables']) || !is_array($this->schema['tables']) || empty($this->schema['tables'])) {
            $errors[] = "'tables' must be a non-empty array of table definitions.";
        } else {
            foreach ($this->schema['tables'] as $tableName => $tableDef) {
                if (!is_string($tableName) || trim($tableName) === '') {
                    $errors[] = "Invalid table name key found in 'tables'.";
                    continue;
                }

                if (!is_array($tableDef)) {
                    $errors[] = "Table '{$tableName}' definition must be an associative array.";
                    continue;
                }

                if (empty($tableDef['primaryKey']) || !is_string($tableDef['primaryKey'])) {
                    $errors[] = "Table '{$tableName}' must specify a valid string 'primaryKey'.";
                }

                if (isset($tableDef['indexes']) && !is_array($tableDef['indexes'])) {
                    $errors[] = "Table '{$tableName}' indexes must be an array.";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Generate an IndexedDB upgrade script / migration instructions array for the client SDK.
     *
     * @return array<string, mixed>
     */
    public function getMigrationManifest(): array
    {
        $validation = $this->validate();
        if (!$validation['valid']) {
            throw new InvalidArgumentException("Cannot generate manifest: " . implode("; ", $validation['errors']));
        }

        $tables = [];
        foreach ($this->schema['tables'] as $tableName => $tableDef) {
            $autoIncrement = (bool)($tableDef['autoIncrement'] ?? false);
            $primaryKey = (string)$tableDef['primaryKey'];
            $indexes = [];

            if (isset($tableDef['indexes']) && is_array($tableDef['indexes'])) {
                foreach ($tableDef['indexes'] as $idxName => $idxConfig) {
                    if (is_string($idxConfig)) {
                        $indexes[$idxConfig] = [
                            'keyPath' => $idxConfig,
                            'unique' => false,
                            'multiEntry' => false,
                        ];
                    } elseif (is_array($idxConfig)) {
                        $indexes[$idxName] = [
                            'keyPath' => $idxConfig['keyPath'] ?? $idxName,
                            'unique' => (bool)($idxConfig['unique'] ?? false),
                            'multiEntry' => (bool)($idxConfig['multiEntry'] ?? false),
                        ];
                    }
                }
            }

            $tables[$tableName] = [
                'name' => $tableName,
                'primaryKey' => $primaryKey,
                'autoIncrement' => $autoIncrement,
                'indexes' => $indexes,
            ];
        }

        return [
            'name' => $this->schema['name'],
            'version' => (int)$this->schema['version'],
            'tables' => $tables,
            'generatedAt' => date('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }
}
