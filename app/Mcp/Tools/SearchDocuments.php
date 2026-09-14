<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_documents')]
#[Description('Search indexed document passages in the authorized project. Returns references to their source documents.')]
#[IsReadOnly]
class SearchDocuments extends SearchMemory
{
    protected string $kind = 'document';
}
