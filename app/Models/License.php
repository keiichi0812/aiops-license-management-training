<?php

namespace App\Models;

use Database\Factories\LicenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int $product_id
 * @property int $user_id
 * @property int $quantity
 * @property bool $stopped
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property-read Customer $customer
 * @property-read Product $product
 * @property-read User $user
 */
#[Fillable(['number', 'customer_id', 'product_id', 'user_id', 'quantity', 'starts_on', 'ends_on', 'stopped', 'notes'])]
class License extends Model
{
    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<License> $query
     * @return Builder<License>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }

    /** @param Builder<License> $query
     * @return Builder<License>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        if ($status === 'stopped') {
            return $query->where('stopped', true);
        }
        $query->where('stopped', false);

        return match ($status) {
            'before' => $query->whereDate('starts_on', '>', today()),
            'expired' => $query->whereDate('ends_on', '<', today()),
            default => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()),
        };
    }

    public function statusLabel(): string
    {
        if ($this->stopped) {
            return '停止中';
        }
        if ($this->starts_on->gt(today())) {
            return '利用開始前';
        }

        return $this->ends_on->lt(today()) ? '期限切れ' : '有効';
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'stopped' => 'boolean', 'quantity' => 'integer'];
    }
}
