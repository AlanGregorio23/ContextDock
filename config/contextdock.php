<?php

return [
    'ollama_url' => env('OLLAMA_URL', 'http://ollama:11434'),
    'embedding_model' => 'qwen3-embedding:0.6b',
    'dimensions' => 1024,
    'query_instruction' => 'Instruct: Given a question, retrieve relevant personal project memories and documentation.\nQuery: ',
    'candidate_limit' => 60,
    'weights' => ['similarity' => .5, 'importance' => .2, 'recency' => .1, 'confidence' => .1, 'usage' => .1],
    'documents_path' => env('DOCUMENTS_PATH', '/documents'),
    'backups_path' => env('BACKUPS_PATH', '/backups'),
    'context_retention_days' => (int) env('CONTEXT_RETENTION_DAYS', 30),
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 90),
    'backup_retention_count' => (int) env('BACKUP_RETENTION_COUNT', 7),
];
