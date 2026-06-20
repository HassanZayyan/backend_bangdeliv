<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $merchant_type
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $phone
 * @property string|null $banner_image
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MenuCategory> $menuCategories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Menu> $menus
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLocation> $orderLocations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 */
class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'merchant_type',
        'address',
        'latitude',
        'longitude',
        'phone',
        'banner_image',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'merchant_type' => 'string',
        ];
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function orderLocations(): HasMany
    {
        return $this->hasMany(OrderLocation::class);
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            OrderLocation::class,
            'restaurant_id',
            'id',
            'id',
            'order_id'
        );
    }
}
