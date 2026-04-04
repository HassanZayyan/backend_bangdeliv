<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function getActiveCart(User $user): ?Cart
    {
        return Cart::query()
            ->where('user_id', $user->id)
            ->whereHas('items')
            ->with(['restaurant', 'items.menu'])
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{cart: array<string, mixed>, created: bool}
     */
    public function addItem(User $user, array $payload): array
    {
        $menu = Menu::query()
            ->where('id', $payload['menu_id'])
            ->where('restaurant_id', $payload['restaurant_id'])
            ->first();

        if (!$menu) {
            throw new ApiException('Menu tidak ditemukan pada restoran yang dipilih.', 404);
        }

        if (!$menu->is_available) {
            throw new ApiException('Menu sedang tidak tersedia.', 409);
        }

        $this->ensureNoCrossRestaurantItems($user, (int) $payload['restaurant_id']);

        return DB::transaction(function () use ($user, $payload): array {
            $cart = Cart::query()->firstOrCreate([
                'user_id' => $user->id,
                'restaurant_id' => $payload['restaurant_id'],
            ]);

            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('menu_id', $payload['menu_id'])
                ->where('notes', $payload['notes'] ?? null)
                ->first();

            $created = false;

            if ($item) {
                $item->update([
                    'quantity' => min(99, $item->quantity + (int) $payload['quantity']),
                ]);
            } else {
                $cart->items()->create([
                    'menu_id' => $payload['menu_id'],
                    'quantity' => (int) $payload['quantity'],
                    'notes' => $payload['notes'] ?? null,
                ]);

                $created = true;
            }

            $cart->touch();

            return [
                'cart' => $this->cartPayload($cart->fresh(['restaurant', 'items.menu'])),
                'created' => $created,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateItem(User $user, int $itemId, array $payload): array
    {
        $item = CartItem::query()->with('cart')->find($itemId);

        if (!$item || $item->cart->user_id !== $user->id) {
            throw new ApiException('Item cart tidak ditemukan.', 404);
        }

        $item->update([
            'quantity' => (int) $payload['quantity'],
            'notes' => $payload['notes'] ?? null,
        ]);

        $item->cart->touch();

        return $this->cartPayload($item->cart->fresh(['restaurant', 'items.menu']));
    }

    public function removeItem(User $user, int $itemId): array
    {
        $item = CartItem::query()->with('cart')->find($itemId);

        if (!$item || $item->cart->user_id !== $user->id) {
            throw new ApiException('Item cart tidak ditemukan.', 404);
        }

        $cart = $item->cart;
        $item->delete();
        $cart->touch();

        return $this->cartPayload($cart->fresh(['restaurant', 'items.menu']));
    }

    public function clear(User $user): array
    {
        $activeCart = $this->getActiveCart($user);

        if (!$activeCart) {
            return $this->emptyPayload();
        }

        $activeCart->items()->delete();
        $activeCart->touch();

        return $this->cartPayload($activeCart->fresh(['restaurant', 'items.menu']));
    }

    /**
     * @return array<string, mixed>
     */
    public function cartPayload(?Cart $cart): array
    {
        if (!$cart || $cart->items->isEmpty()) {
            return $this->emptyPayload();
        }

        $subtotal = 0.0;
        $totalItems = 0;

        $items = $cart->items->map(function (CartItem $item) use (&$subtotal, &$totalItems): array {
            $lineSubtotal = (float) $item->menu->price * $item->quantity;
            $subtotal += $lineSubtotal;
            $totalItems += $item->quantity;

            return [
                'id' => $item->id,
                'menu_id' => $item->menu_id,
                'menu_name' => $item->menu->name,
                'unit_price' => (float) $item->menu->price,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'subtotal' => round($lineSubtotal, 2),
            ];
        })->values()->all();

        return [
            'cart_id' => $cart->id,
            'restaurant' => [
                'id' => $cart->restaurant->id,
                'name' => $cart->restaurant->name,
                'slug' => $cart->restaurant->slug,
            ],
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'total_items' => $totalItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPayload(): array
    {
        return [
            'cart_id' => null,
            'restaurant' => null,
            'items' => [],
            'subtotal' => 0,
            'total_items' => 0,
        ];
    }

    private function ensureNoCrossRestaurantItems(User $user, int $restaurantId): void
    {
        $hasDifferentRestaurantItems = Cart::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', '!=', $restaurantId)
            ->whereHas('items')
            ->exists();

        if ($hasDifferentRestaurantItems) {
            throw new ApiException('Cart aktif hanya boleh untuk satu restoran. Selesaikan atau kosongkan cart saat ini.', 409);
        }
    }
}
