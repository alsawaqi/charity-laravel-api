<?php

use App\Mcp\Servers\CharityMcpServer;
use Laravel\Mcp\Facades\Mcp;

// Mcp::web('/mcp/demo', \App\Mcp\Servers\PublicServer::class);
Mcp::local('charity', CharityMcpServer::class);

Mcp::web('/mcp/charity', CharityMcpServer::class)
    ->middleware(['auth:sanctum', 'dashboard.access', 'dashboard.permission:dashboard.ai.view']);
