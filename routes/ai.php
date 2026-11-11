<?php

use App\Http\Middleware\EnsureMcpTokenAuthenticated;
use App\Http\Middleware\LogMcpToolCalls;
use App\Mcp\Servers\PulsoServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/pulso', PulsoServer::class)->middleware([EnsureMcpTokenAuthenticated::class, LogMcpToolCalls::class]);
