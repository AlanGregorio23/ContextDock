<?php

use App\Mcp\ContextDockOrigin;
use App\Mcp\ContextDockServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', ContextDockServer::class)
    ->middleware([ContextDockOrigin::class, 'auth:sanctum', 'context.token', 'throttle:context']);
