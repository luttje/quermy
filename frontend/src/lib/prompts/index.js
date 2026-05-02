import promptTemplate from './quermy-system.md?raw';

export const ENGINE_LABELS = {
    mysql:      'MySQL',
    mariadb:    'MariaDB',
    postgresql: 'PostgreSQL',
    sqlite:     'SQLite',
    sqlserver:  'SQL Server',
};

const ENGINE_UUID_GUIDANCE = {
    mysql: "When inserting into tables whose primary key is a UUID/GUID, prefer letting the database generate the value with `UUID()` or `UUID_TO_BIN(UUID(), 1)` rather than inventing one. Note in the rationale that application-generated UUIDs (e.g. Laravel's `HasUuids`, which uses ordered v7 UUIDs) follow a specific distribution; values you generate in a query will not match that distribution and may fragment indexes or sort differently. When in doubt, recommend the user create the row through the application instead.",

    mariadb: "When inserting into tables whose primary key is a UUID/GUID, prefer letting the database generate the value with `UUID()` rather than inventing one. Note in the rationale that application-generated UUIDs may follow a different distribution and could fragment indexes. When in doubt, recommend the user create the row through the application instead.",

    postgresql: "When inserting into tables whose primary key is a UUID/GUID, prefer letting the database generate the value with `gen_random_uuid()` (PostgreSQL 13+) or `uuid_generate_v4()` (requires the `uuid-ossp` extension) rather than inventing one. When in doubt, recommend the user create the row through the application instead.",

    sqlite: "When inserting into tables whose primary key is a UUID/GUID, SQLite has no built-in UUID function. Recommend generating the UUID at the application layer rather than in SQL. When in doubt, recommend the user create the row through the application instead.",

    sqlserver: "When inserting into tables whose primary key is a UUID/GUID (`uniqueidentifier`), prefer letting the database generate the value with `NEWID()` or `NEWSEQUENTIALID()` (sequential — better for clustered indexes) rather than inventing one. When in doubt, recommend the user create the row through the application instead.",
};

const DEFAULT_UUID_GUIDANCE =
    "When inserting into tables whose primary key is a UUID/GUID, prefer letting the database generate the value using its native UUID function rather than inventing one. When in doubt, recommend the user create the row through the application instead.";

/**
 * Builds the system prompt for the given engine ID (e.g. 'mysql', 'postgresql').
 * Omit or pass an empty string for a generic SQL prompt when no engine is connected.
 */
export function buildSystemPrompt(engineId = '') {
    const engineName = ENGINE_LABELS[engineId] ?? 'SQL';
    const uuidGuidance = ENGINE_UUID_GUIDANCE[engineId] ?? DEFAULT_UUID_GUIDANCE;
    return promptTemplate
        .replace(/\{\{ENGINE_NAME\}\}/g, engineName)
        .replace(/\{\{UUID_GUIDANCE\}\}/g, uuidGuidance);
}

// Generic fallback — kept for the prompt editor's "Reset to default" when no engine is connected.
export const quermySystemPrompt = buildSystemPrompt();
