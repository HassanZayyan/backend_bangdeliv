<?php

namespace App\Services\Shopping;

use App\Models\Restaurant;

class ShoppingMerchantCandidate
{
    /**
     * @param  array<int, string>  $placeTypes
     */
    private function __construct(
        public readonly ?Restaurant $restaurant,
        public readonly ?string $placeId,
        public readonly string $name,
        public readonly string $address,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly array $placeTypes = [],
    ) {}

    public static function fromRestaurant(Restaurant $restaurant): self
    {
        return new self(
            restaurant: $restaurant,
            placeId: null,
            name: (string) $restaurant->name,
            address: (string) $restaurant->address,
            latitude: (float) $restaurant->latitude,
            longitude: (float) $restaurant->longitude,
        );
    }

    /**
     * @param  array<int, string>  $placeTypes
     */
    public static function fromGooglePlace(
        ?string $placeId,
        string $name,
        string $address,
        float $latitude,
        float $longitude,
        array $placeTypes = [],
    ): self {
        return new self(
            restaurant: null,
            placeId: $placeId,
            name: $name,
            address: $address,
            latitude: $latitude,
            longitude: $longitude,
            placeTypes: $placeTypes,
        );
    }

    public function isDatabaseMerchant(): bool
    {
        return $this->restaurant instanceof Restaurant;
    }

    public function key(): string
    {
        if ($this->restaurant instanceof Restaurant) {
            return 'restaurant:'.(int) $this->restaurant->id;
        }

        if ($this->placeId !== null && $this->placeId !== '') {
            return 'place:'.$this->placeId;
        }

        return sprintf('external:%s:%0.6f:%0.6f', $this->normalizedName(), $this->latitude, $this->longitude);
    }

    public function normalizedName(): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $this->name) ?? $this->name));
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        if ($this->restaurant instanceof Restaurant) {
            return [
                'source' => 'CUSTOMER_MERCHANT_DB',
                'restaurant_id' => (int) $this->restaurant->id,
            ];
        }

        return [
            'source' => 'CUSTOMER_GOOGLE_PLACE',
            'place_id' => $this->placeId,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'types' => $this->placeTypes,
        ];
    }
}
