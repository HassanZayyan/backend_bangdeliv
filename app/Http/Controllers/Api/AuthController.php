<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAddressRequest;
use App\Http\Requests\Api\UpdateAddressRequest;
use App\Http\Requests\Api\UpgradeToDriverRequest;
use App\Http\Requests\Api\ValidateAddressRequest;
use App\Models\OrderPayment;
use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\WhatsAppOtpSender;
use App\Services\Address\AddressService;
use App\Services\Driver\DriverIncomeFeeCalculator;
use App\Services\Driver\DriverOnboardingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            'name' => trim((string) $request->input('name', '')),
            'email' => strtolower((string) $request->input('email', '')),
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
            'password' => $request->input('password'),
        ];

        $validator = Validator::make($payload, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[0-9]{10,15}$/',
                'unique:users,phone',
            ],
            'password' => 'required|string|min:8',
        ], [
            'phone.regex' => 'Format nomor WhatsApp tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make((string) $validated['password']),
            'role' => 'customer',
            'google_sub' => null,
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);

        return $this->issueAuthTokenResponse($user->fresh(), 'Pendaftaran customer berhasil.', 201);
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
    ) {
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

        if (
            ! $user ||
            trim((string) ($user->password ?? '')) === '' ||
            ! Hash::check($payload['password'], (string) $user->password)
        ) {
            return response()->json(['message' => 'Kredensial tidak valid.'], 401);
        }

        $user = $user->fresh();
        $deniedResponse = $this->denyInactiveOrBlacklisted($user);
        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        return $this->issueAuthTokenResponse($user, 'Login berhasil.');
    }

    /**
     * Reset password for accounts that already use BangDeliv password login.
     */
    public function resetPassword(Request $request)
    {
        $payload = [
            'email' => strtolower((string) $request->input('email', '')),
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
            'new_password' => $request->input('new_password'),
            'new_password_confirmation' => $request->input('new_password_confirmation'),
        ];

        $validator = Validator::make($payload, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{10,15}$/'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'phone.regex' => 'Format nomor WhatsApp tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = User::query()
            ->where('email', $validated['email'])
            ->where('phone', $validated['phone'])
            ->first();

        if (! $user || trim((string) ($user->password ?? '')) === '') {
            return response()->json([
                'message' => 'Data akun tidak cocok. Periksa email dan nomor WhatsApp.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make((string) $validated['new_password']),
        ]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diatur ulang.',
        ]);
    }

    /**
     * Login or register customer using a verified Google ID token.
     */
    public function loginWithGoogle(Request $request, GoogleIdTokenVerifier $verifier)
    {
        $payload = [
            'id_token' => trim((string) $request->input('id_token', '')),
        ];

        $validator = Validator::make($payload, [
            'id_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $googleUser = $verifier->verify($payload['id_token']);
        } catch (\Throwable $exception) {
            Log::warning('Google Sign-In token verification failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Token Google tidak valid. Silakan coba masuk ulang.',
            ], 401);
        }

        if (! $googleUser['email_verified']) {
            return response()->json([
                'message' => 'Email Google belum terverifikasi.',
            ], 422);
        }

        $user = DB::transaction(function () use ($googleUser): User {
            $user = User::query()->where('google_sub', $googleUser['sub'])->first();

            if (! $user) {
                $user = User::query()->where('email', $googleUser['email'])->first();
                if ($user && trim((string) ($user->google_sub ?? '')) !== '') {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Email Google ini sudah terhubung dengan akun Google lain.',
                    ], 409));
                }
            }

            $name = trim($googleUser['name']);
            if ($name === '') {
                $name = strstr($googleUser['email'], '@', true) ?: 'Pengguna BangDeliv';
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $googleUser['email'],
                    'google_sub' => $googleUser['sub'],
                    'avatar' => $googleUser['picture'],
                    'email_verified_at' => now(),
                    'phone' => null,
                    'password' => null,
                    'role' => 'customer',
                ]);

                return $user->fresh();
            }

            $updates = [
                'google_sub' => $googleUser['sub'],
            ];
            if (trim((string) ($user->avatar ?? '')) === '' && $googleUser['picture'] !== null) {
                $updates['avatar'] = $googleUser['picture'];
            }
            if ($user->email_verified_at === null) {
                $updates['email_verified_at'] = now();
            }

            $user->update($updates);

            return $user->fresh();
        });

        $user = $user->fresh();
        $deniedResponse = $this->denyInactiveOrBlacklisted($user);
        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        return $this->issueAuthTokenResponse($user, 'Login Google berhasil');
    }

    /**
     * Get Current Profile
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $responseUserData = $this->buildProfilePayload($user, $request);

        return response()->json([
            'data' => $responseUserData,
        ]);
    }

    /**
     * Update Current Profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $isDriver = $user->role === 'driver';

        $payload = [
            'name' => $request->input('name'),
            'email' => $request->filled('email') ? strtolower((string) $request->input('email')) : null,
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
            'avatar' => $request->file('avatar'),
            'remove_avatar' => $request->boolean('remove_avatar'),
        ];
        if ($isDriver || $request->has('vehicle_type')) {
            $payload['vehicle_type'] = trim((string) $request->input('vehicle_type', ''));
        }
        if ($isDriver || $request->has('vehicle_brand')) {
            $payload['vehicle_brand'] = trim((string) $request->input('vehicle_brand', ''));
        }
        if ($isDriver || $request->has('vehicle_model')) {
            $payload['vehicle_model'] = trim((string) $request->input('vehicle_model', ''));
        }

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
            'vehicle_type' => [$isDriver ? 'required' : 'nullable', 'string', 'max:50'],
            'vehicle_brand' => [$isDriver ? 'required' : 'nullable', 'string', 'max:50'],
            'vehicle_model' => [$isDriver ? 'required' : 'nullable', 'string', 'max:100'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ], [
            'vehicle_type.required' => 'Jenis kendaraan wajib dipilih.',
            'vehicle_brand.required' => 'Merk kendaraan wajib dipilih.',
            'vehicle_model.required' => 'Model kendaraan wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $currentEmail = strtolower(trim((string) $user->email));
        $submittedEmail = strtolower(trim((string) ($validated['email'] ?? $user->email)));

        if (trim((string) ($user->google_sub ?? '')) !== '' && $submittedEmail !== $currentEmail) {
            return response()->json([
                'errors' => [
                    'email' => ['Email Google tidak dapat diubah dari profil.'],
                ],
            ], 422);
        }

        $oldAvatarPath = is_string($user->avatar) ? $user->avatar : null;
        $avatarPath = $oldAvatarPath;
        $shouldRemoveAvatar = (bool) ($validated['remove_avatar'] ?? false);

        if ($shouldRemoveAvatar) {
            $avatarPath = null;
        }

        if (isset($validated['avatar']) && $validated['avatar'] !== null) {
            $avatarPath = $validated['avatar']->store('avatars/'.$user->id, 'public');
        }

        if (
            $oldAvatarPath &&
            $oldAvatarPath !== $avatarPath &&
            ! $this->isRemoteAvatarUrl($oldAvatarPath) &&
            Storage::disk('public')->exists($oldAvatarPath)
        ) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        $userUpdates = [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $submittedEmail,
            'avatar' => $avatarPath,
        ];

        if ((string) $user->phone !== (string) $validated['phone']) {
            $userUpdates['phone_verified_at'] = null;
        }

        $user->update($userUpdates);

        if ($isDriver) {
            $driver = $user->driver()->first();
            if ($driver) {
                $driverUpdates = [
                    'vehicle_type' => trim((string) $validated['vehicle_type']),
                    'vehicle_brand' => trim((string) $validated['vehicle_brand']),
                    'vehicle_model' => trim((string) $validated['vehicle_model']),
                ];

                if (! empty($driverUpdates)) {
                    $driver->update($driverUpdates);
                }
            }
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data' => $this->buildProfilePayload($user->fresh(), $request),
        ]);
    }

    /**
     * Complete phone number for Google-created accounts.
     */
    public function completePhone(Request $request)
    {
        $user = $request->user();
        $payload = [
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
        ];

        $validator = Validator::make($payload, [
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[0-9]{10,15}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ], [
            'phone.regex' => 'Format nomor WhatsApp tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedPhone = $validator->validated()['phone'];
        $updates = [
            'phone' => $validatedPhone,
        ];
        if ((string) $user->phone !== (string) $validatedPhone) {
            $updates['phone_verified_at'] = null;
        }

        $user->update($updates);

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil disimpan.',
            'data' => $this->buildProfilePayload($user->fresh(), $request),
        ]);
    }

    /**
     * Kirim kode OTP verifikasi ke nomor WhatsApp user yang sedang login.
     */
    public function sendPhoneOtp(Request $request, WhatsAppOtpSender $sender)
    {
        $user = $request->user();
        $phone = trim((string) ($user->phone ?? ''));

        if ($phone === '') {
            return response()->json([
                'message' => 'Nomor WhatsApp belum diisi.',
            ], 422);
        }

        if ($user->phone_verified_at !== null) {
            return response()->json([
                'message' => 'Nomor WhatsApp sudah terverifikasi.',
                'data' => [
                    'already_verified' => true,
                    'resend_available_in' => 0,
                ],
            ]);
        }

        $cooldownSeconds = (int) config('bangdeliv.otp.resend_cooldown_seconds', 60);
        $existing = PhoneVerificationCode::query()->where('user_id', $user->id)->first();

        if ($existing && $existing->phone === $phone && $existing->last_sent_at !== null) {
            $secondsSinceLastSend = $existing->last_sent_at->diffInSeconds(now());
            if ($secondsSinceLastSend < $cooldownSeconds) {
                $retryAfter = (int) ceil($cooldownSeconds - $secondsSinceLastSend);

                return response()->json([
                    'message' => "Tunggu {$retryAfter} detik sebelum meminta kode baru.",
                    'retry_after_seconds' => $retryAfter,
                ], 429);
            }
        }

        $code = (string) random_int(100000, 999999);

        // Kirim dulu, simpan belakangan: kegagalan gateway tidak boleh
        // memulai cooldown sehingga user bisa langsung mencoba lagi.
        if (! $sender->send($phone, $code)) {
            return response()->json([
                'message' => 'Gagal mengirim kode verifikasi WhatsApp. Coba lagi.',
            ], 503);
        }

        PhoneVerificationCode::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('bangdeliv.otp.ttl_minutes', 5)),
                'attempts' => 0,
                'last_sent_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Kode verifikasi telah dikirim ke WhatsApp Anda.',
            'data' => [
                'already_verified' => false,
                'resend_available_in' => $cooldownSeconds,
            ],
        ]);
    }

    /**
     * Verifikasi kode OTP nomor WhatsApp.
     */
    public function verifyPhoneOtp(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make(
            ['code' => trim((string) $request->input('code', ''))],
            ['code' => 'required|digits:6'],
            [
                'code.required' => 'Kode OTP wajib diisi.',
                'code.digits' => 'Kode OTP harus 6 digit.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($user->phone_verified_at !== null) {
            return response()->json([
                'message' => 'Nomor WhatsApp sudah terverifikasi.',
                'data' => $this->buildProfilePayload($user->fresh(), $request),
            ]);
        }

        $record = PhoneVerificationCode::query()->where('user_id', $user->id)->first();
        $currentPhone = trim((string) ($user->phone ?? ''));

        if (! $record || $record->phone !== $currentPhone) {
            return response()->json([
                'message' => 'Kode OTP tidak ditemukan. Kirim ulang kode.',
            ], 422);
        }

        if ($record->isExpired()) {
            return response()->json([
                'message' => 'Kode OTP kedaluwarsa. Kirim ulang kode.',
            ], 422);
        }

        $maxAttempts = (int) config('bangdeliv.otp.max_attempts', 5);
        if ($record->attempts >= $maxAttempts) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan. Kirim ulang kode baru.',
            ], 422);
        }

        if (! Hash::check($validator->validated()['code'], $record->code_hash)) {
            $record->increment('attempts');
            $remaining = max(0, $maxAttempts - ($record->attempts));

            return response()->json([
                'message' => $remaining > 0
                    ? "Kode OTP salah. Sisa percobaan: {$remaining}."
                    : 'Terlalu banyak percobaan. Kirim ulang kode baru.',
            ], 422);
        }

        $user->update(['phone_verified_at' => now()]);
        $record->delete();

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil diverifikasi.',
            'data' => $this->buildProfilePayload($user->fresh(), $request),
        ]);
    }

    /**
     * Update current user password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (trim((string) ($user->password ?? '')) === '') {
            return response()->json([
                'errors' => [
                    'current_password' => ['Akun Google belum memiliki password BangDeliv.'],
                ],
            ], 422);
        }

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

        if (! Hash::check((string) $payload['current_password'], (string) $user->password)) {
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
     * Create the first password for Google-only accounts.
     */
    public function createPassword(Request $request)
    {
        $user = $request->user();

        if (trim((string) ($user->password ?? '')) !== '') {
            return response()->json([
                'errors' => [
                    'new_password' => ['Akun sudah memiliki password. Gunakan menu ganti password.'],
                ],
            ], 422);
        }

        $payload = [
            'new_password' => $request->input('new_password'),
            'new_password_confirmation' => $request->input('new_password_confirmation'),
        ];

        $validator = Validator::make($payload, [
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'password' => Hash::make((string) $payload['new_password']),
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Password BangDeliv berhasil dibuat.',
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

        if (! $address) {
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

        if (! $address) {
            return response()->json(['message' => 'Alamat tidak ditemukan.'], 404);
        }

        DB::transaction(function () use ($address, $user) {
            $wasDefault = (bool) $address->is_default;

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

        return response()->json(['message' => 'Logout berhasil.']);
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
                'latitude',
                'longitude',
                'is_default',
            ]);

        $driver = null;
        $totalOrders = $user->orders()->count();
        $totalPaid = (float) OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
            ->where('payment_status', 'PAID')
            ->sum('amount');

        if ($user->role === 'driver') {
            $driver = $user->driver()->first();

            if ($driver) {
                $completedDriverOrders = $driver->orders()
                    ->whereHas('statusRef', function ($query): void {
                        $query->where('code', 'COMPLETED');
                    });
                $incomeDriverOrders = $driver->orders()
                    ->whereHas('statusRef', function ($query): void {
                        $query->whereIn('code', ['COMPLETED', 'CANCELLED_WITH_FEE']);
                    });

                $completed = (clone $completedDriverOrders)
                    ->with(['serviceType', 'statusRef', 'orderLocations'])
                    ->get();
                $incomeOrders = (clone $incomeDriverOrders)
                    ->with(['serviceType', 'statusRef', 'orderLocations'])
                    ->get();
                $incomeCalculator = app(DriverIncomeFeeCalculator::class);
                $totalOrders = $completed->count();
                $totalPaid = (float) $incomeOrders->sum(function ($order) use ($incomeCalculator): float {
                    $grossIncome = $incomeCalculator->grossIncomeForOrder($order);

                    return (float) $incomeCalculator->breakdown($grossIncome)['net_income'];
                });
            }
        }

        $responseUserData = $user->toArray();
        $responseUserData['address_count'] = $addresses->count();
        $responseUserData['addresses'] = $addresses->toArray();
        $responseUserData['driver_profile'] = $driver?->toArray();
        $responseUserData['avatar_url'] = $this->resolveAvatarUrl($user, $request);
        $responseUserData['auth_provider'] = trim((string) ($user->google_sub ?? '')) !== ''
            ? 'google'
            : 'password';
        $responseUserData['has_password'] = trim((string) ($user->password ?? '')) !== '';
        $responseUserData['requires_phone_completion'] = trim((string) ($user->phone ?? '')) === '';
        $responseUserData['requires_phone_verification'] = $user->requiresPhoneVerification();
        $responseUserData['stats'] = [
            'total_orders' => $totalOrders,
            'total_paid' => $totalPaid,
        ];

        return $responseUserData;
    }

    private function resolveAvatarUrl(User $user, ?Request $request = null): ?string
    {
        $avatarPath = trim((string) ($user->avatar ?? ''));
        if ($avatarPath === '') {
            return null;
        }

        if ($this->isRemoteAvatarUrl($avatarPath)) {
            return $avatarPath;
        }

        if (! Storage::disk('public')->exists($avatarPath)) {
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

    private function isRemoteAvatarUrl(string $avatar): bool
    {
        return str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://');
    }

    private function denyInactiveOrBlacklisted(User $user): ?\Illuminate\Http\JsonResponse
    {
        if (! $user->is_active) {
            return response()->json(['message' => 'Akun Anda dinonaktifkan. Silakan hubungi Admin.'], 403);
        }

        if ($user->is_blacklisted) {
            return response()->json(['message' => 'Akun Anda diblokir karena indikasi fraud.'], 403);
        }

        return null;
    }

    private function issueAuthTokenResponse(User $user, string $message, int $status = 200): \Illuminate\Http\JsonResponse
    {
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        $responseUserData = $this->buildProfilePayload($user->fresh());

        return response()->json([
            'message' => $message,
            'data' => $responseUserData,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], $status);
    }
}
