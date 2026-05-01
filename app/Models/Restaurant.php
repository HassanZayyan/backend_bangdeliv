<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $phone
 * @property string|null $banner_image
 * @property string $status
 * @property string|null $avg_rating
 * @property int $total_reviews
 * @property int|null $estimated_prep_time
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RestaurantOperatingHour> $operatingHours
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MenuCategory> $menuCategories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Menu> $menus
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Review> $reviews
 */
class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'address',
        'latitude',
        'longitude',
        'phone',
        'banner_image',
        'status',
        'avg_rating',
        'total_reviews',
        'estimated_prep_time',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'avg_rating' => 'decimal:2',
            'total_reviews' => 'integer',
            'estimated_prep_time' => 'integer',
        ];
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(RestaurantOperatingHour::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
