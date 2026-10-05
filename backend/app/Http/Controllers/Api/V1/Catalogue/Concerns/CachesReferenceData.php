<?php

namespace App\Http\Controllers\Api\V1\Catalogue\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

trait CachesReferenceData
{
    /**
     * Reference data changes rarely: let apps and proxies keep it for a few minutes
     * (slow mobile networks). Only successful responses go through this method.
     */
    protected function cacheable(JsonResource $resource): JsonResponse
    {
        $response = $resource->response();
        $seconds = (int) config('digivitrine.reference_cache_seconds');

        if ($seconds > 0) {
            $response->setCache(['public' => true, 'max_age' => $seconds]);
        }

        return $response;
    }
}
