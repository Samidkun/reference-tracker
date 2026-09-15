<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateReferenceRequest extends StoreReferenceRequest
{
    // Same shape as create; kept as its own class so the two can diverge
    // later (e.g. PATCH semantics) without touching call sites.
}
