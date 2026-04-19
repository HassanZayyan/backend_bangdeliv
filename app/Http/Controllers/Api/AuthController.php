<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAddressRequest;
use App\Http\Requests\Api\UpdateAddressRequest;
use App\Http\Requests\Api\UpgradeToDriverRequest;
use App\Http\Requests\Api\ValidateAddressRequest;
use App\Models\User;
use App\Models\Review;
use App\Services\AddressService;
use App\Services\DriverOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    /**
     * Register Customer
     */
    public function registerCustomer(Request $request)
    {
        $payload = [
            'name' => $request->input('name'),
            'email' => strtolower((string) $request->input('email', '')),
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
            'password' => $request->input('password'),
        ];

        $validator = Validator::make($payload, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => 'customer',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Customer registered successfully',
            'data' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
    }

    /**
     * Upgrade authenticated customer to driver
     */
    public function upgradeToDriver(
        UpgradeToDriverRequest $request,
        DriverOnboardingService $service
    )
    {
        try {
            $payload = $service->upgradeCustomerToDriver(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'message' => 'Upgrade ke driver berhasil. Dokumen menunggu verifikasi admin.',
                'data' => $payload,
            ], 201);
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Upgrade akun driver gagal. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * User Login (Global for Customer, Driver, Admin)
     */
    public function login(Request $request)
    {
        $payload = [
            'email' => strtolower((string) $request->input('email', '')),
            'password' => $request->input('password'),
        ];

        $validator = Validator::make($payload, [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $payload['email'])->first();

        if (!$user || !Hash::check($payload['password'], $user->password)) {
            return response()->json(['message' => 'Kredensial tidak valid.'], 401);
        }

        // Anti-Fraud Checks
        if (!$user->is_active) {
            return response()->json(['message' => 'Akun Anda dinonaktifkan. Silakan hubungi Admin.'], 403);
        }

        if ($user->is_blacklisted) {
            return response()->json(['message' => 'Akun Anda diblokir karena indikasi fraud.'], 403);
        }

        // Revoke older tokens to ensure clean session (optional)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        $responseUserData = $user->toArray();
        if ($user->role === 'driver') {
            $responseUserData['driver_profile'] = $user->driver;
        }

        return response()->json([
            'message' => 'Login successful',
            'data' => $responseUserData,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 200);
    }

    /**
     * Get Current Profile
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $responseUserData = $this->buildProfilePayload($user, $request);

        return response()->json([
            'data' => $responseUserData
        ]);
    }

    /**
     * Update Current Profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $payload = [
            'name' => $request->input('name'),
            'email' => $request->filled('email') ? strtolower((string) $request->input('email')) : null,
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
            'avatar' => $request->file('avatar'),
            'remove_avatar' => $request->boolean('remove_avatar'),
        ];

        $validator = Validator::make($payload, [
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $oldAvatarPath = is_string($user->avatar) ? $user->avatar : null;
        $avatarPath = $oldAvatarPath;
        $shouldRemoveAvatar = (bool) ($validated['remove_avatar'] ?? false);

        if ($shouldRemoveAvatar) {
            $avatarPath = null;
        }

        if (isset($validated['avatar']) && $validated['avatar'] !== null) {
            $avatarPath = $validated['avatar']->store('avatars/'.$user->id, 'public');
        }

        if ($oldAvatarPath && $oldAvatarPath !== $avatarPath && Storage::disk('public')->exists($oldAvatarPath)) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        $user->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? $user->email,
            'avatar' => $avatarPath,
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data' => $this->buildProfilePayload($user->fresh(), $request),
        ]);
    }

    /**
     * Update current user password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $payload = [
            'current_password' => $request->input('current_password'),
            'new_password' => $request->input('new_password'),
            'new_password_confirmation' => $request->input('new_password_confirmation'),
        ];

        $validator = Validator::make($payload, [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check((string) $payload['current_password'], (string) $user->password)) {
            return response()->json([
                'errors' => [
                    'current_password' => ['Password saat ini tidak sesuai.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make((string) $payload['new_password']),
        ]);

        // Rotate tokens so sessions use fresh credentials.
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Password berhasil diperbarui.',
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Store a new saved address for current user
     */
    public function storeAddress(StoreAddressRequest $request, AddressService $addressService)
    {
        try {
            $address = $addressService->store($request->user(), $request->validated());

            return response()->json([
                'message' => 'Alamat berhasil disimpan.',
                'data' => $address,
            ], 201);
        } catch (ApiException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }
    }

    /**
     * Update saved address for current user
     */
    public function updateAddress(UpdateAddressRequest $request, int $addressId, AddressService $addressService)
    {
        $user = $request->user();
        $address = $user->addresses()->whereKey($addressId)->first();

        if (!$address) {
            return response()->json(['message' => 'Alamat tidak ditemukan.'], 404);
        }

        try {
            $updatedAddress = $addressService->update($user, $address, $request->validated());

            return response()->json([
                'message' => 'Alamat berhasil diperbarui.',
                'data' => $updatedAddress,
            ]);
        } catch (ApiException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }
    }

    /**
     * Validate candidate address and return normalized coordinates.
     */
    public function validateAddress(ValidateAddressRequest $request, AddressService $addressService)
    {
        try {
            $resolvedAddress = $addressService->validateAddress((string) $request->input('full_address'));

            return response()->json([
                'message' => 'Alamat valid.',
                'data' => $resolvedAddress,
            ]);
        } catch (ApiException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }
    }

    /**
     * Delete saved address for current user
     */
    public function deleteAddress(Request $request, int $addressId)
    {
        $user = $request->user();
        $address = $user->addresses()->whereKey($addressId)->first();

        if (!$address) {
            return response()->json(['message' => 'Alamat tidak ditemukan.'], 404);
        }

        DB::transaction(function () use ($address, $user) {
            $wasDefault = (bool) $address->is_default;

            // Keep order history intact while allowing address deletion.
            DB::table('orders')
                ->where('address_id', $address->id)
                ->update(['address_id' => null]);

            $address->delete();

            if ($wasDefault) {
                $replacementDefault = $user->addresses()->latest('id')->first();
                if ($replacementDefault) {
                    $replacementDefault->update(['is_default' => true]);
                }
            }
        });

        return response()->json([
            'message' => 'Alamat berhasil dihapus.',
        ]);
    }

    /**
     * User Logout
     */
    public function logout(Request $request)
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    private function buildProfilePayload(User $user, ?Request $request = null): array
    {
        $addresses = $user->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->get([
                'id',
                'label',
                'recipient_name',
                'phone',
                'full_address',
                'detail',
                'latitude',
                'longitude',
                'is_default',
            ]);

        $driver = null;
        $totalOrders = $user->orders()->count();
        $totalPaid = (float) $user->orders()->where('payment_status', 'paid')->sum('total_amount');
        $rating = (float) (Review::query()->where('user_id', $user->id)->avg('rating') ?? 0);

        if ($user->role === 'driver') {
            $driver = $user->driver()->first();

            if ($driver) {
                $completedDriverOrders = $driver->orders()
                    ->whereHas('statusRef', function ($query): void {
                        $query->where('code', 'COMPLETED');
                    });

                $totalOrders = (clone $completedDriverOrders)->count();
                $totalPaid = (float) (clone $completedDriverOrders)->sum('delivery_fee');

                $avgRating = Review::query()
                    ->where('driver_id', $driver->id)
                    ->avg('rating');

                if ($avgRating !== null) {
                    $rating = (float) $avgRating;
                } elseif ($driver->avg_rating !== null) {
                    $rating = (float) $driver->avg_rating;
                } else {
                    $rating = 0;
                }
            }
        }

        $responseUserData = $user->toArray();
        $responseUserData['address_count'] = $addresses->count();
        $responseUserData['addresses'] = $addresses->toArray();
        $responseUserData['driver_profile'] = $driver?->toArray();
        $responseUserData['avatar_url'] = $this->resolveAvatarUrl($user, $request);
        $responseUserData['stats'] = [
            'total_orders' => $totalOrders,
            'total_paid' => $totalPaid,
            'rating' => round($rating, 1),
        ];

        return $responseUserData;
    }

    private function resolveAvatarUrl(User $user, ?Request $request = null): ?string
    {
        $avatarPath = trim((string) ($user->avatar ?? ''));
        if ($avatarPath === '') {
            return null;
        }

        if (!Storage::disk('public')->exists($avatarPath)) {
            return null;
        }

        $relativeUrl = Storage::disk('public')->url($avatarPath);
        if (str_starts_with($relativeUrl, 'http://') || str_starts_with($relativeUrl, 'https://')) {
            return $relativeUrl;
        }

        $baseUrl = $request
            ? rtrim($request->getSchemeAndHttpHost(), '/')
            : rtrim((string) config('app.url'), '/');

        if ($baseUrl === '') {
            return $relativeUrl;
        }

        return $baseUrl.'/'.ltrim($relativeUrl, '/');
    }
}
