<?php

namespace App\Http\Middleware;

use App\Services\Developer\OperationAccounting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class TrackApiOperation
{
    public function handle(Request $request, Closure $next): mixed
    {
        Context::forgetHidden(OperationAccounting::CONTEXT);
        try {
            return $next($request);
        } finally {
            $id = OperationAccounting::current();
            try {
                if ($id) {
                    try { OperationAccounting::close($id); } finally { \App\Services\Developer\OperationFence::release($id); }
                }
            } finally {
                Context::forgetHidden(OperationAccounting::CONTEXT);
            }
        }
    }
}
