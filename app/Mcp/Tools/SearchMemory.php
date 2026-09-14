<?php

namespace App\Mcp\Tools;

use App\Data\Input;
use App\Models\User;
use App\Services\RetrievalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_memory')]
#[Description('Search relevant memories in a project and its authorized inherited scopes using local semantic retrieval.')]
#[IsReadOnly]
class SearchMemory extends ContextTool
{
    protected string $kind = 'memory';

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'query' => $schema->string()->max(2000)->required(),
            'limit' => $schema->integer()->min(1)->max(30)->default(10),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        $input = Input::search($user, $request->all());
        $input['kind'] = $this->kind;
        $result = app(RetrievalService::class)->search($user, $input);

        return ['candidates' => $result['candidates'], 'embedding' => $result['embedding']];
    }
}
