<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiRouteContractTest extends TestCase
{
    #[DataProvider('flutterRouteContracts')]
    public function test_flutter_api_route_contract_is_registered(string $name, string $method, string $uri): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route name [{$name}] is not registered.");
        $this->assertSame($uri, $route->uri(), "Route [{$name}] URI changed.");
        $this->assertContains($method, $route->methods(), "Route [{$name}] method changed.");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function flutterRouteContracts(): array
    {
        return [
            'auth login' => ['api.auth.login', 'POST', 'api/auth/login'],
            'auth register customer' => ['api.auth.register-customer', 'POST', 'api/auth/register/customer'],
            'profile show' => ['api.user.show', 'GET', 'api/user'],
            'address validate' => ['api.user.addresses.validate', 'POST', 'api/user/addresses/validate'],
            'address store' => ['api.user.addresses.store', 'POST', 'api/user/addresses'],
            'address update' => ['api.user.addresses.update', 'PUT', 'api/user/addresses/{addressId}'],
            'chatbot process' => ['api.chatbot.process', 'POST', 'api/chatbot/process'],
            'chatbot sessions' => ['api.chatbot.sessions.index', 'GET', 'api/chatbot/sessions'],
            'home' => ['api.v1.home', 'GET', 'api/v1/home'],
            'restaurants index' => ['api.v1.restaurants.index', 'GET', 'api/v1/restaurants'],
            'restaurant menus' => ['api.v1.restaurants.menus', 'GET', 'api/v1/restaurants/{restaurantIdOrSlug}/menus'],
            'customer orders index' => ['api.v1.orders.index', 'GET', 'api/v1/orders'],
            'customer order detail' => ['api.v1.orders.show', 'GET', 'api/v1/orders/{orderId}'],
            'customer order cancel' => ['api.v1.orders.cancel', 'POST', 'api/v1/orders/{orderId}/cancel'],
            'ride validate destination' => ['api.v1.orders.ride.validate-destination', 'POST', 'api/v1/orders/ride/validate-destination'],
            'ride create' => ['api.v1.orders.ride.store', 'POST', 'api/v1/orders/ride'],
            'shopping item bulk store' => ['api.v1.orders.items.bulk-store', 'POST', 'api/v1/orders/{orderId}/items/bulk'],
            'shopping stop skip' => ['api.v1.orders.shopping-stops.skip', 'POST', 'api/v1/orders/{orderId}/shopping-stops/{pickupLocationId}/skip'],
            'shopping price quote respond' => ['api.v1.orders.shopping.price-quote.respond', 'POST', 'api/v1/orders/{orderId}/shopping/price-quote/respond'],
            'delivery fee quote respond' => ['api.v1.orders.delivery-fee-override.respond', 'POST', 'api/v1/orders/{orderId}/delivery-fee-override/respond'],
            'customer transfer evidence' => ['api.v1.orders.payment.transfer.evidence', 'POST', 'api/v1/orders/{orderId}/payment/transfer/evidence'],
            'order chat messages' => ['api.v1.orders.chat.messages.index', 'GET', 'api/v1/orders/{orderId}/chat/messages'],
            'order chat send' => ['api.v1.orders.chat.messages.store', 'POST', 'api/v1/orders/{orderId}/chat/messages'],
            'device token store' => ['api.v1.device-tokens.store', 'POST', 'api/v1/device-tokens'],
            'driver availability' => ['api.v1.driver.availability.show', 'GET', 'api/v1/driver/availability'],
            'driver current location update' => ['api.v1.driver.location.update', 'PATCH', 'api/v1/driver/location'],
            'driver orders index' => ['api.v1.driver.orders.index', 'GET', 'api/v1/driver/orders'],
            'driver order detail' => ['api.v1.driver.orders.show', 'GET', 'api/v1/driver/orders/{orderId}'],
            'driver accept order' => ['api.v1.driver.orders.accept', 'POST', 'api/v1/driver/orders/{orderId}/accept'],
            'driver reject order' => ['api.v1.driver.orders.reject', 'POST', 'api/v1/driver/orders/{orderId}/reject'],
            'driver status transition' => ['api.v1.driver.orders.status-transition', 'POST', 'api/v1/driver/orders/{orderId}/status-transition'],
            'driver location update' => ['api.v1.driver.orders.location.update', 'PATCH', 'api/v1/driver/orders/{orderId}/location'],
            'driver proof upload' => ['api.v1.driver.orders.proofs.store', 'POST', 'api/v1/driver/orders/{orderId}/proofs'],
            'driver shopping checkout' => ['api.v1.driver.orders.shopping-checkout.update', 'PATCH', 'api/v1/driver/orders/{orderId}/shopping-checkout'],
            'driver shopping items update' => ['api.v1.driver.orders.shopping-items.update', 'PATCH', 'api/v1/driver/orders/{orderId}/shopping-items'],
            'driver shopping price quote' => ['api.v1.driver.orders.shopping.price-quote.store', 'POST', 'api/v1/driver/orders/{orderId}/shopping/price-quote'],
            'driver shopping accept counter' => ['api.v1.driver.orders.shopping.price-quote.accept-counter', 'POST', 'api/v1/driver/orders/{orderId}/shopping/price-quote/accept-counter'],
            'driver delivery fee accept counter' => ['api.v1.driver.orders.delivery-fee-override.accept-counter', 'POST', 'api/v1/driver/orders/{orderId}/delivery-fee-override/accept-counter'],
            'driver failed attempt' => ['api.v1.driver.orders.attempt-failed', 'POST', 'api/v1/orders/{orderId}/attempt-failed'],
            'driver cod payment' => ['api.v1.driver.orders.payment.collect-cod', 'POST', 'api/v1/orders/{orderId}/payment/collect-cod'],
            'driver transfer payment' => ['api.v1.driver.orders.payment.transfer.confirm', 'POST', 'api/v1/orders/{orderId}/payment/transfer/confirm'],
        ];
    }
}
