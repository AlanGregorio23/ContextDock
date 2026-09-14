<?php

namespace App\Data;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Input
{
    public const TYPES = ['preference','fact','decision','instruction','task','summary','note','architecture','entity','temporary'];

    public static function memoryRules(): array
    {
        return [
            'scope' => ['required', Rule::in(['global','workspace','project','session'])],
            'workspace_id' => ['nullable','integer','min:1'], 'project_id' => ['nullable','integer','min:1'],
            'conversation_id' => ['nullable','integer','min:1'], 'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required','string','max:200'], 'content' => ['required','string','max:12000'],
            'summary' => ['nullable','string','max:2000'], 'importance' => ['sometimes','numeric','between:0,1'],
            'confidence' => ['sometimes','numeric','between:0,1'], 'is_pinned' => ['sometimes','boolean'],
            'expires_at' => ['nullable','date'], 'source_type' => ['sometimes','string','max:50'],
            'source_id' => ['nullable','string','max:200'], 'metadata' => ['sometimes','array','max:20'],
        ];
    }

    public static function searchRules(): array
    {
        return ['project_id' => ['required','integer','min:1'], 'query' => ['required','string','max:2000'],
            'conversation_id' => ['nullable','integer','min:1'], 'limit' => ['sometimes','integer','between:1,30'],
            'budget_tokens' => ['sometimes','integer','between:512,12000'],
            'kind' => ['nullable', Rule::in(['memory','document'])]];
    }

    public static function search(User $user, array $data): array
    {
        return Validator::make($data, self::searchRules())->validate();
    }

    public static function memory(User $user, array $data): array
    {
        $valid = Validator::make($data, self::memoryRules())->validate();
        if (strlen(json_encode($valid['metadata'] ?? [])) > 4096) {
            throw \Illuminate\Validation\ValidationException::withMessages(['metadata' => 'Metadata troppo grandi.']);
        }
        return $valid;
    }
}
