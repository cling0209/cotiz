<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Locations')]
class ChileLocationController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/locations/chile', summary: 'Regiones y comunas de Chile', tags: ['Locations'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function index(): JsonResponse
    {
        $path = database_path('data/chile_regions.json');

        if (! File::exists($path)) {
            return $this->success([]);
        }

        return $this->success(json_decode(File::get($path), true));
    }
}
