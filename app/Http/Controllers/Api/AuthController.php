<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Register Customer
     */
    public function registerCustomer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
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

    /**
     * Register Driver
     */
    public function registerDriver(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'vehicle_plate' => 'required|string|max:20',
            'license_number' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => 'driver',
            ]);

            $driver = Driver::create([
                'user_id' => $user->id,
                'vehicle_plate' => $request->vehicle_plate,
                'license_number' => $request->license_number,
                'registration_status' => 'pending',
                'status' => 'offline',
            ]);

            DB::commit();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Driver registered successfully. Pending admin approval.',
                'data' => [
                    'user' => $user,
                    'driver_profile' => $driver
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Registration failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * User Login (Global for Customer, Driver, Admin)
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
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
        $responseUserData = $user->toArray();
        
        if ($user->role === 'driver') {
            $responseUserData['driver_profile'] = $user->driver;
        }

        return response()->json([
            'data' => $responseUserData
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
}
