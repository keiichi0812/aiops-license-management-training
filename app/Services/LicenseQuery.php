<?php

namespace App\Services;

use App\Models\License;
use Illuminate\Database\Eloquent\Builder;

class LicenseQuery
{
    /** @param Builder<License> $query
     * @return Builder<License>
     */
    public function expiring(Builder $query): Builder
    {
        return $query->status('active')->whereDate('ends_on', '<=', today()->addDays(30));
    }
}
