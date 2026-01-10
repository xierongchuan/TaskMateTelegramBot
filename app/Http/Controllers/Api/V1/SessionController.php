<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SessionController extends Controller
{
    public function store(Request $req)
    {
        \Illuminate\Support\Facades\Log::info('Login attempt', ['login' => $req->login]);

        $req->validate([
            'login'    => ['required', 'min:4', 'max:64', 'regex:/^(?!.*\..*\.)(?!.*_.*_)[a-zA-Z0-9._]+$/'],
            'password' => 'required|min:6|max:255',
        ]);

        try {
            $user = User::where('login', $req->login)->first();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Login DB Error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка базы данных'], 500);
        }

        if (! $user || ! Hash::check($req->password, $user->password)) {
            \Illuminate\Support\Facades\Log::warning('Login failed: Invalid credentials', ['login' => $req->login]);
            return response()->json(['message' => 'Неверные данные'], 401);
        }

        $token = $user->createToken('user-token')->plainTextToken;
        \Illuminate\Support\Facades\Log::info('Login successful', ['user_id' => $user->id]);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'dealership_id' => $user->dealership_id,
                'telegram_id' => $user->telegram_id,
                'phone' => $user->phone,
                'dealerships' => $user->dealerships,
            ],
        ]);
    }

    public function destroy(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Logout initiated', ['user_id' => $request->user()->id]);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Сессия завершена']);
    }

    public function current(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('Session check failed: No user found from token');
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        \Illuminate\Support\Facades\Log::info('Session check successful', ['user_id' => $user->id]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'dealership_id' => $user->dealership_id,
                'telegram_id' => $user->telegram_id,
                'phone' => $user->phone,
                'dealerships' => $user->dealerships,
            ],
        ]);
    }
}
