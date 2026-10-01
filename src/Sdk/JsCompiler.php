<?php

declare(strict_types=1);

namespace EidCloud\PwaDb\Sdk;

/**
 * Compiles the lightweight, modern client-side IndexedDB JavaScript SDK.
 * Includes fluent query builder, offline mutation queue, schema migrations,
 * and Background Sync API integration.
 */
class JsCompiler
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $defaultSchema;

    /**
     * @param array<string, mixed>|null $defaultSchema
     */
    public function __construct(?array $defaultSchema = null)
    {
        $this->defaultSchema = $defaultSchema;
    }

    /**
     * Generate the complete standalone JavaScript client SDK file.
     *
     * @param array<string, mixed>|null $schema
     * @param array{syncEndpoint?: string, enableBackgroundSync?: bool} $options
     * @return string
     */
    public function compile(?array $schema = null, array $options = []): string
    {
        $effectiveSchema = $schema ?? $this->defaultSchema ?? [
            'name' => 'EidCloudPwaDb',
            'version' => 1,
            'tables' => [
                'customers' => [
                    'primaryKey' => 'id',
                    'autoIncrement' => false,
                    'indexes' => ['status', 'email', 'updated_at']
                ]
            ]
        ];

        $encodedSchema = json_encode($effectiveSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $syncEndpoint = $options['syncEndpoint'] ?? '/api/pwa-sync';
        $enableBgSync = !empty($options['enableBackgroundSync']);

        return <<<JS
/**
 * EidCloud PWA DB - Client IndexedDB Storage & Offline Sync SDK
 * Version: 1.0.0
 * Pure JavaScript with zero external dependencies.
 * Licensed under MIT (c) 2026 MHD. Shadi AL-Hasan
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EidCloudPwaDb = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const DEFAULT_SCHEMA = {$encodedSchema};
    const SYNC_QUEUE_TABLE = '__eidcloud_sync_queue';

    class QueryBuilder {
        constructor(db, tableName) {
            this.db = db;
            this.tableName = tableName;
            this.conditions = [];
            this.orderField = null;
            this.orderDirection = 'asc';
            this.limitCount = null;
            this.offsetCount = 0;
        }

        where(field, operator, value) {
            this.conditions.push({ field, operator, value });
            return this;
        }

        orderBy(field, direction = 'asc') {
            this.orderField = field;
            this.orderDirection = direction.toLowerCase() === 'desc' ? 'desc' : 'asc';
            return this;
        }

        limit(count) {
            this.limitCount = count;
            return this;
        }

        offset(count) {
            this.offsetCount = count;
            return this;
        }

        async get() {
            const rawRecords = await this.db.getAllFromStore(this.tableName);
            let results = rawRecords.filter(item => this.matchesConditions(item));

            if (this.orderField) {
                results.sort((a, b) => {
                    const valA = a[this.orderField];
                    const valB = b[this.orderField];
                    if (valA === valB) return 0;
                    if (valA === undefined || valA === null) return 1;
                    if (valB === undefined || valB === null) return -1;
                    const cmp = valA > valB ? 1 : -1;
                    return this.orderDirection === 'desc' ? -cmp : cmp;
                });
            }

            if (this.offsetCount > 0) {
                results = results.slice(this.offsetCount);
            }

            if (this.limitCount !== null) {
                results = results.slice(0, this.limitCount);
            }

            return results;
        }

        async first() {
            const previousLimit = this.limitCount;
            this.limitCount = 1;
            const records = await this.get();
            this.limitCount = previousLimit;
            return records.length > 0 ? records[0] : null;
        }

        async count() {
            const results = await this.get();
            return results.length;
        }

        matchesConditions(item) {
            for (const cond of this.conditions) {
                const val = item[cond.field];
                switch (cond.operator) {
                    case '=':
                    case '==':
                        if (val != cond.value) return false;
                        break;
                    case '===':
                        if (val !== cond.value) return false;
                        break;
                    case '!=':
                    case '<>':
                        if (val == cond.value) return false;
                        break;
                    case '!==':
                        if (val === cond.value) return false;
                        break;
                    case '>':
                        if (!(val > cond.value)) return false;
                        break;
                    case '>=':
                        if (!(val >= cond.value)) return false;
                        break;
                    case '<':
                        if (!(val < cond.value)) return false;
                        break;
                    case '<=':
                        if (!(val <= cond.value)) return false;
                        break;
                    case 'in':
                        if (!Array.isArray(cond.value) || !cond.value.includes(val)) return false;
                        break;
                    case 'like':
                        if (typeof val !== 'string') return false;
                        const pattern = new RegExp('^' + cond.value.replace(/%/g, '.*') + '$', 'i');
                        if (!pattern.test(val)) return false;
                        break;
                    default:
                        if (val != cond.value) return false;
                }
            }
            return true;
        }
    }

    class TableRepository {
        constructor(db, tableName) {
            this.db = db;
            this.tableName = tableName;
        }

        where(field, operator, value) {
            const qb = new QueryBuilder(this.db, this.tableName);
            return qb.where(field, operator, value);
        }

        orderBy(field, direction) {
            const qb = new QueryBuilder(this.db, this.tableName);
            return qb.orderBy(field, direction);
        }

        limit(count) {
            const qb = new QueryBuilder(this.db, this.tableName);
            return qb.limit(count);
        }

        async get() {
            const qb = new QueryBuilder(this.db, this.tableName);
            return await qb.get();
        }

        async find(id) {
            return await this.db.getRecord(this.tableName, id);
        }

        async insert(record) {
            const primaryKeyField = this.db.getPrimaryKey(this.tableName);
            if (!record[primaryKeyField]) {
                record[primaryKeyField] = this.db.generateUuid();
            }
            if (!record.created_at) record.created_at = new Date().toISOString();
            if (!record.updated_at) record.updated_at = new Date().toISOString();

            await this.db.putRecord(this.tableName, record);
            await this.db.enqueueMutation({
                action: 'insert',
                table: this.tableName,
                recordId: record[primaryKeyField],
                data: record
            });

            return record;
        }

        async update(id, updates) {
            const existing = await this.find(id);
            if (!existing) {
                throw new Error('Record not found with ID: ' + id);
            }
            const updated = Object.assign({}, existing, updates, {
                updated_at: new Date().toISOString()
            });

            await this.db.putRecord(this.tableName, updated);
            await this.db.enqueueMutation({
                action: 'update',
                table: this.tableName,
                recordId: id,
                data: updated
            });

            return updated;
        }

        async delete(id) {
            const existing = await this.find(id);
            await this.db.deleteRecord(this.tableName, id);
            await this.db.enqueueMutation({
                action: 'delete',
                table: this.tableName,
                recordId: id,
                data: existing || { id }
            });
            return true;
        }
    }

    class Database {
        constructor(config = {}) {
            this.schema = config.schema || DEFAULT_SCHEMA;
            this.dbName = this.schema.name || 'EidCloudPwaDb';
            this.version = this.schema.version || 1;
            this.syncEndpoint = config.syncEndpoint || '{$syncEndpoint}';
            this.idb = null;
            this.listeners = {};
        }

        async open() {
            if (this.idb) return this.idb;

            return new Promise((resolve, reject) => {
                const request = indexedDB.open(this.dbName, this.version);

                request.onupgradeneeded = (event) => {
                    const db = event.target.result;
                    this.performMigrations(db);
                };

                request.onsuccess = (event) => {
                    this.idb = event.target.result;
                    resolve(this.idb);
                };

                request.onerror = (event) => {
                    reject(event.target.error);
                };
            });
        }

        performMigrations(db) {
            // Ensure internal mutation queue table
            if (!db.objectStoreNames.contains(SYNC_QUEUE_TABLE)) {
                db.createObjectStore(SYNC_QUEUE_TABLE, { keyPath: 'id' });
            }

            const tables = this.schema.tables || {};
            for (const [tableName, tableDef] of Object.entries(tables)) {
                let store;
                const primaryKey = tableDef.primaryKey || 'id';
                const autoIncrement = !!tableDef.autoIncrement;

                if (!db.objectStoreNames.contains(tableName)) {
                    store = db.createObjectStore(tableName, { keyPath: primaryKey, autoIncrement });
                } else {
                    store = event.currentTarget.transaction.objectStore(tableName);
                }

                if (tableDef.indexes && Array.isArray(tableDef.indexes)) {
                    for (const indexDef of tableDef.indexes) {
                        const idxName = typeof indexDef === 'string' ? indexDef : indexDef.name;
                        const keyPath = typeof indexDef === 'string' ? indexDef : (indexDef.keyPath || indexDef.name);
                        const unique = typeof indexDef === 'object' ? !!indexDef.unique : false;

                        if (!store.indexNames.contains(idxName)) {
                            store.createIndex(idxName, keyPath, { unique });
                        }
                    }
                }
            }
        }

        table(name) {
            return new TableRepository(this, name);
        }

        getPrimaryKey(tableName) {
            const tableDef = this.schema.tables && this.schema.tables[tableName];
            return (tableDef && tableDef.primaryKey) || 'id';
        }

        generateUuid() {
            if (typeof crypto !== 'undefined' && crypto.randomUUID) {
                return crypto.randomUUID();
            }
            return 'tx-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        }

        async getRecord(tableName, id) {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(tableName, 'readonly');
                const store = tx.objectStore(tableName);
                const req = store.get(id);
                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => reject(req.error);
            });
        }

        async putRecord(tableName, record) {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(tableName, 'readwrite');
                const store = tx.objectStore(tableName);
                const req = store.put(record);
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        }

        async deleteRecord(tableName, id) {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(tableName, 'readwrite');
                const store = tx.objectStore(tableName);
                const req = store.delete(id);
                req.onsuccess = () => resolve(true);
                req.onerror = () => reject(req.error);
            });
        }

        async getAllFromStore(tableName) {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(tableName, 'readonly');
                const store = tx.objectStore(tableName);
                const req = store.getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        }

        async enqueueMutation(mutation) {
            const txEntry = {
                id: this.generateUuid(),
                table: mutation.table,
                action: mutation.action,
                recordId: mutation.recordId,
                data: mutation.data,
                timestamp: Date.now(),
                retryCount: 0
            };
            await this.putRecord(SYNC_QUEUE_TABLE, txEntry);
            this.triggerEvent('mutationEnqueued', txEntry);

            // Register Background Sync API if supported and configured
            this.requestBackgroundSync();
            return txEntry;
        }

        async getPendingMutations() {
            return await this.getAllFromStore(SYNC_QUEUE_TABLE);
        }

        async removePendingMutation(txId) {
            return await this.deleteRecord(SYNC_QUEUE_TABLE, txId);
        }

        async sync() {
            const pending = await this.getPendingMutations();
            if (pending.length === 0) {
                return { status: 'idle', count: 0 };
            }

            const payload = {
                clientId: this.getClientId(),
                timestamp: Date.now(),
                transactions: pending
            };

            try {
                const res = await fetch(this.syncEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    throw new Error('Sync server responded with HTTP ' + res.status);
                }

                const result = await res.json();
                if (result.status === 'success' && Array.isArray(result.applied)) {
                    for (const appliedItem of result.applied) {
                        await this.removePendingMutation(appliedItem.transactionId);
                    }
                }

                this.triggerEvent('synced', result);
                return result;
            } catch (err) {
                this.triggerEvent('syncFailed', { error: err.message, pendingCount: pending.length });
                throw err;
            }
        }

        requestBackgroundSync() {
            if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator && 'SyncManager' in window) {
                navigator.serviceWorker.ready.then(reg => {
                    return reg.sync.register('eidcloud-pwa-db-sync');
                }).catch(() => {
                    // Fallback to online event or next user interaction
                });
            }
        }

        getClientId() {
            let id = typeof localStorage !== 'undefined' ? localStorage.getItem('eidcloud_pwa_client_id') : null;
            if (!id) {
                id = this.generateUuid();
                if (typeof localStorage !== 'undefined') {
                    localStorage.setItem('eidcloud_pwa_client_id', id);
                }
            }
            return id;
        }

        on(event, callback) {
            if (!this.listeners[event]) this.listeners[event] = [];
            this.listeners[event].push(callback);
        }

        triggerEvent(event, data) {
            const cbs = this.listeners[event] || [];
            for (const cb of cbs) {
                try { cb(data); } catch (e) { console.error(e); }
            }
        }
    }

    return {
        create: (config) => new Database(config),
        Database,
        QueryBuilder,
        TableRepository
    };
}));
JS;
    }

    /**
     * Save the compiled JavaScript SDK to a target directory.
     *
     * @param string $outputDir
     * @param string $filename
     * @param array<string, mixed>|null $schema
     * @return string Absolute file path of the written SDK
     */
    public function saveTo(string $outputDir, string $filename = 'eidcloud-pwa-db.js', ?array $schema = null): string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $code = $this->compile($schema);
        $filePath = rtrim($outputDir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($filePath, $code);

        return $filePath;
    }
}
