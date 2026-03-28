<?php

namespace App\Services\Auth;

use App\Models\LogisticsSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DataScopeService
{
    /**
     * @var array<int, array<int, int>|null>
     */
    private array $siteIdsCache = [];

    public function serializeUser(User $user): array
    {
        $scope = $user->resolveDataScope();

        return array_merge($user->toArray(), [
            'permissions' => $user->resolvePermissions(),
            'data_scope_type' => $scope['type'],
            'data_scope' => [
                'region_codes' => $scope['region_codes'],
                'site_ids' => $scope['site_ids'],
            ],
        ]);
    }

    public function canAccessSite(?User $user, ?int $siteId): bool
    {
        if (! $user || $siteId === null || $this->bypassScope($user)) {
            return true;
        }

        $siteIds = $this->resolveAccessibleSiteIds($user);

        return $siteIds === null || in_array($siteId, $siteIds, true);
    }

    public function canAccessSites(?User $user, array $siteIds): bool
    {
        foreach ($siteIds as $siteId) {
            if (! $this->canAccessSite($user, $siteId !== null ? (int) $siteId : null)) {
                return false;
            }
        }

        return true;
    }

    public function applySiteScope(Builder $query, ?User $user, string $table = 'logistics_sites'): Builder
    {
        $siteIds = $this->resolveAccessibleSiteIds($user);
        if ($siteIds === null) {
            return $query;
        }

        return $query->whereIn($table.'.id', $siteIds);
    }

    public function applyVehicleScope(Builder $query, ?User $user, string $table = 'vehicles'): Builder
    {
        if ($user && $user->hasRole('driver')) {
            return $query->where($table.'.driver_id', $user->id);
        }

        $siteIds = $this->resolveAccessibleSiteIds($user);
        if ($siteIds === null) {
            return $query;
        }

        return $query->whereIn($table.'.site_id', $siteIds);
    }

    public function applyPrePlanOrderScope(Builder $query, ?User $user, string $table = 'pre_plan_orders'): Builder
    {
        $siteIds = $this->resolveAccessibleSiteIds($user);
        if ($siteIds === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($table, $siteIds): void {
            $builder->whereIn($table.'.pickup_site_id', $siteIds)
                ->orWhereIn($table.'.dropoff_site_id', $siteIds);
        });
    }

    public function applyDispatchTaskScope(Builder $query, ?User $user, string $table = 'dispatch_tasks'): Builder
    {
        if ($user && $user->hasRole('driver')) {
            return $query->where($table.'.driver_id', $user->id);
        }

        $siteIds = $this->resolveAccessibleSiteIds($user);
        if ($siteIds === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($siteIds): void {
            $builder->whereHas('vehicle', function (Builder $vehicleQuery) use ($siteIds): void {
                $vehicleQuery->whereIn('vehicles.site_id', $siteIds);
            })->orWhereHas('orders', function (Builder $orderQuery) use ($siteIds): void {
                $orderQuery->where(function (Builder $orderBuilder) use ($siteIds): void {
                    $orderBuilder->whereIn('pre_plan_orders.pickup_site_id', $siteIds)
                        ->orWhereIn('pre_plan_orders.dropoff_site_id', $siteIds);
                });
            });
        });
    }

    public function applyUserScope(Builder $query, ?User $user, string $table = 'users'): Builder
    {
        if (! $user || $this->bypassScope($user)) {
            return $query;
        }

        $siteIds = $this->resolveAccessibleSiteIds($user);
        if ($siteIds === null) {
            return $query;
        }

        $scope = $user->resolveDataScope();
        if ($siteIds === [] && $scope['region_codes'] === []) {
            return $query->where($table.'.id', $user->id);
        }

        return $query->where(function (Builder $builder) use ($table, $user, $siteIds, $scope): void {
            $builder->where($table.'.id', $user->id)
                ->orWhereHas('vehicle', function (Builder $vehicleQuery) use ($siteIds): void {
                    $vehicleQuery->whereIn('vehicles.site_id', $siteIds);
                })
                ->orWhere(function (Builder $scopeQuery) use ($table, $scope, $siteIds): void {
                    $scopeQuery->where($table.'.data_scope_type', 'all');

                    if ($siteIds !== []) {
                        $scopeQuery->orWhere(function (Builder $siteScopeQuery) use ($table, $siteIds): void {
                            foreach ($siteIds as $siteId) {
                                $siteScopeQuery->orWhereJsonContains($table.'.data_scope->site_ids', $siteId);
                            }
                        });
                    }

                    if ($scope['region_codes'] !== []) {
                        $scopeQuery->orWhere(function (Builder $regionScopeQuery) use ($table, $scope): void {
                            foreach ($scope['region_codes'] as $regionCode) {
                                $regionScopeQuery->orWhereJsonContains($table.'.data_scope->region_codes', $regionCode);
                            }
                        });
                    }
                })
                ->where($table.'.role', '!=', 'admin');
        });
    }

    /**
     * @return array<int, int>|null
     */
    public function resolveAccessibleSiteIds(?User $user): ?array
    {
        if (! $user || $this->bypassScope($user)) {
            return null;
        }

        if (array_key_exists($user->id, $this->siteIdsCache)) {
            return $this->siteIdsCache[$user->id];
        }

        $scope = $user->resolveDataScope();
        if ($scope['type'] === 'all') {
            return $this->siteIdsCache[$user->id] = null;
        }

        if ($scope['type'] === 'site') {
            return $this->siteIdsCache[$user->id] = $scope['site_ids'];
        }

        if ($scope['region_codes'] === []) {
            return $this->siteIdsCache[$user->id] = [];
        }

        return $this->siteIdsCache[$user->id] = LogisticsSite::query()
            ->whereIn('region_code', $scope['region_codes'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function bypassScope(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
