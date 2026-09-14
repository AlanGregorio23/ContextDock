<?php

namespace App\Mcp\Tools;

use App\Data\Input;
use App\Models\User;
use App\Services\ContextBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_project_context')]
#[Description('Build a compact Context Pack for a query, including authorized memories, decisions, pinned items, and document passages. Returns context only; no AI generation.')]
#[IsReadOnly]
class GetProjectContext extends SearchMemory
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'budget_tokens' => $schema->integer()->min(512)->max(12000)->description('Approximate context token budget; the service enforces its configured maximum.'),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        return app(ContextBuilder::class)->build($user, Input::search($user, $request->all()))['pack'];
    }
}
