<?php

namespace App\Services\Freight;

use App\Models\FreightRateTemplate;
use App\Models\User;
use App\Services\Auth\DataScopeService;

class FreightTemplateMatcherService
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function match(array $payload, ?User $user = null): ?FreightRateTemplate
    {
        $query = FreightRateTemplate::query()
            ->where('is_active', true)
            ->when(! empty($payload['client_name']), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('client_name')->orWhere('client_name', (string) $payload['client_name']);
            }))
            ->when(! empty($payload['cargo_category_id']), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('cargo_category_id')->orWhere('cargo_category_id', (int) $payload['cargo_category_id']);
            }))
            ->when(array_key_exists('pickup_site_id', $payload), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('pickup_site_id')->orWhere('pickup_site_id', $payload['pickup_site_id']);
            }))
            ->when(! empty($payload['pickup_address']), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('pickup_address')->orWhere('pickup_address', (string) $payload['pickup_address']);
            }))
            ->when(array_key_exists('dropoff_site_id', $payload), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('dropoff_site_id')->orWhere('dropoff_site_id', $payload['dropoff_site_id']);
            }))
            ->when(! empty($payload['dropoff_address']), fn ($q) => $q->where(function ($sub) use ($payload): void {
                $sub->whereNull('dropoff_address')->orWhere('dropoff_address', (string) $payload['dropoff_address']);
            }));

        $siteIds = $this->dataScopeService->resolveAccessibleSiteIds($user);
        if ($siteIds !== null) {
            $query
                ->where(function ($builder) use ($siteIds): void {
                    $builder->whereNull('pickup_site_id')
                        ->orWhereIn('pickup_site_id', $siteIds);
                })
                ->where(function ($builder) use ($siteIds): void {
                    $builder->whereNull('dropoff_site_id')
                        ->orWhereIn('dropoff_site_id', $siteIds);
                });
        }

        return $query
            ->orderByRaw('case when pickup_site_id is null then 0 else 1 end desc')
            ->orderByRaw('case when dropoff_site_id is null then 0 else 1 end desc')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }
}
