<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CargoCategory;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'app' => 'TaskRoute',
            'version' => 'v1',
            'roles' => ['admin', 'dispatcher', 'driver', 'customer'],
            'cargo_categories' => CargoCategory::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('id')
                ->get(),
            'dispatch_modes' => [
                'single_vehicle_single_order',
                'single_vehicle_multi_order',
                'multi_vehicle_single_order',
                'multi_vehicle_multi_order',
            ],
        ]);
    }
}
