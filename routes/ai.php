<?php

use App\Mcp\Servers\PulsoServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/pulso', PulsoServer::class);
