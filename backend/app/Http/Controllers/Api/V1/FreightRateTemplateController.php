<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FreightRateTemplate;
use App\Services\Auth\DataScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FreightRateTemplateController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'cargo_category_id' => ['nullable', 'integer', 'exists:cargo_categories,id'],
            'pickup_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
        ]);

        $keyword = trim((string) ($payload['keyword'] ?? ''));

        $data = $this->scopedTemplateQuery($request)
            ->with(['pickupSite:id,name', 'dropoffSite:id,name'])
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('client_name', 'like', "%{$keyword}%")
                        ->orWhere('pickup_address', 'like', "%{$keyword}%")
                        ->orWhere('dropoff_address', 'like', "%{$keyword}%");
                });
            })
            ->when(array_key_exists('is_active', $payload), fn ($query) => $query->where('is_active', (bool) $payload['is_active']))
            ->when($payload['cargo_category_id'] ?? null, fn ($query, $id) => $query->where('cargo_category_id', (int) $id))
            ->when(array_key_exists('pickup_site_id', $payload), fn ($query) => $query->where('pickup_site_id', $payload['pickup_site_id']))
            ->when(array_key_exists('dropoff_site_id', $payload), fn ($query) => $query->where('dropoff_site_id', $payload['dropoff_site_id']))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        if (! $this->dataScopeService->canAccessSites($request->user(), [$payload['pickup_site_id'] ?? null, $payload['dropoff_site_id'] ?? null])) {
            return response()->json(['message' => '当前账号不可配置该范围内的运价模板'], 403);
        }
        $template = FreightRateTemplate::query()->create($payload);

        return response()->json($template, 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:freight_rate_templates,id'],
        ]);

        return response()->json(
            $this->scopedTemplateQuery($request)
                ->with(['pickupSite:id,name', 'dropoffSite:id,name'])
                ->findOrFail($payload['id'])
        );
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request, true);
        $template = $this->scopedTemplateQuery($request)->findOrFail((int) $payload['id']);
        $targetPickupSiteId = array_key_exists('pickup_site_id', $payload) ? $payload['pickup_site_id'] : $template->pickup_site_id;
        $targetDropoffSiteId = array_key_exists('dropoff_site_id', $payload) ? $payload['dropoff_site_id'] : $template->dropoff_site_id;
        if (! $this->dataScopeService->canAccessSites($request->user(), [$targetPickupSiteId, $targetDropoffSiteId])) {
            return response()->json(['message' => '当前账号不可配置该范围内的运价模板'], 403);
        }
        unset($payload['id']);
        $template->update($payload);

        return response()->json($template->fresh()->loadMissing(['pickupSite:id,name', 'dropoffSite:id,name']));
    }

    private function validatePayload(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'name' => $forUpdate ? ['sometimes', 'required', 'string', 'max:100'] : ['required', 'string', 'max:100'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cargo_category_id' => ['sometimes', 'nullable', 'integer', 'exists:cargo_categories,id'],
            'pickup_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dropoff_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'freight_calc_scheme' => $forUpdate ? ['sometimes', 'required', 'in:by_weight,by_volume,by_trip'] : ['required', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'freight_trip_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'loss_allowance_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
        if ($forUpdate) {
            $rules['id'] = ['required', 'integer', 'exists:freight_rate_templates,id'];
        }

        $payload = $request->validate($rules);
        $existing = null;
        if ($forUpdate) {
            $existing = FreightRateTemplate::query()->findOrFail((int) $payload['id']);
        }

        $scheme = (string) ($payload['freight_calc_scheme'] ?? $existing?->freight_calc_scheme ?? '');
        $unitPrice = array_key_exists('freight_unit_price', $payload) ? $payload['freight_unit_price'] : $existing?->freight_unit_price;
        $tripCount = array_key_exists('freight_trip_count', $payload) ? $payload['freight_trip_count'] : $existing?->freight_trip_count;

        if ($scheme !== '' && $unitPrice === null) {
            throw ValidationException::withMessages([
                'freight_unit_price' => ['当前运价方式必须填写运价单价'],
            ]);
        }
        if ($scheme === 'by_trip' && $tripCount === null) {
            throw ValidationException::withMessages([
                'freight_trip_count' => ['按趟计费时必须填写趟数'],
            ]);
        }
        if ($scheme !== 'by_trip') {
            $payload['freight_trip_count'] = null;
        }

        if (! array_key_exists('loss_allowance_kg', $payload) && ! $forUpdate) {
            $payload['loss_allowance_kg'] = 0;
        }

        return $payload;
    }

    private function scopedTemplateQuery(Request $request): Builder
    {
        $query = FreightRateTemplate::query();
        $siteIds = $this->dataScopeService->resolveAccessibleSiteIds($request->user());
        if ($siteIds === null) {
            return $query;
        }

        return $query
            ->where(function (Builder $builder) use ($siteIds): void {
                $builder->whereNull('pickup_site_id')
                    ->orWhereIn('pickup_site_id', $siteIds);
            })
            ->where(function (Builder $builder) use ($siteIds): void {
                $builder->whereNull('dropoff_site_id')
                    ->orWhereIn('dropoff_site_id', $siteIds);
            });
    }
}
