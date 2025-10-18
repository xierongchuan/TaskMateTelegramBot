<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Enums\Role;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Enum;

class UserApiController extends Controller
{
    public function store(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->role !== Role::OWNER->value && $user->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'login' => 'required|string|min:4|max:255|unique:users,login',
            'password' => 'required|string|min:8|max:255',
            'role' => ['required', new Enum(Role::class)],
            'dealership_id' => 'required|integer|exists:auto_dealerships,id',
            'phone' => 'nullable|string|max:20',
        ]);

        // Manager can only create employees in their own dealership
        if ($user->role === Role::MANAGER->value) {
            if ($validated['role'] !== Role::EMPLOYEE->value || $validated['dealership_id'] !== $user->dealership_id) {
                return response()->json(['message' => 'Вы не можете создавать пользователей с этой ролью или в этом салоне'], 403);
            }
        }

        $newUser = User::create([
            'full_name' => $validated['full_name'],
            'login' => $validated['login'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'dealership_id' => $validated['dealership_id'],
            'phone' => $validated['phone'] ?? null,
            'status' => 'active', // New users are active by default
        ]);

        return new UserResource($newUser);
    }

    public function update(Request $request, User $user)
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if ($currentUser->role !== Role::OWNER->value && $currentUser->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        // Manager can only update employees in their own dealership
        if ($currentUser->role === Role::MANAGER->value) {
            if ($user->dealership_id !== $currentUser->dealership_id || $user->role !== Role::EMPLOYEE->value) {
                return response()->json(['message' => 'Вы не можете редактировать этого пользователя'], 403);
            }
        }

        $validated = $request->validate([
            'full_name' => 'sometimes|required|string|max:255',
            'login' => 'sometimes|required|string|min:4|max:255|unique:users,login,' . $user->id,
            'password' => 'nullable|string|min:8|max:255',
            'role' => ['sometimes', 'required', new Enum(Role::class)],
            'dealership_id' => 'sometimes|required|integer|exists:auto_dealerships,id',
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|required|string|in:active,inactive',
        ]);

        // Additional check for manager permissions
        if ($currentUser->role === Role::MANAGER->value) {
            // Prevent manager from changing role or dealership
            if (isset($validated['role']) && $validated['role'] !== Role::EMPLOYEE->value) {
                return response()->json(['message' => 'Вы не можете изменять роль пользователя'], 403);
            }
            if (isset($validated['dealership_id']) && $validated['dealership_id'] !== $currentUser->dealership_id) {
                return response()->json(['message' => 'Вы не можете изменять автосалон пользователя'], 403);
            }
        }

        $user->update($validated);

        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $user->save();
        }

        return new UserResource($user);
    }

    public function destroy(User $user)
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if ($currentUser->role !== Role::OWNER->value && $currentUser->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        // Manager can only deactivate employees in their own dealership
        if ($currentUser->role === Role::MANAGER->value) {
            if ($user->dealership_id !== $currentUser->dealership_id || $user->role !== Role::EMPLOYEE->value) {
                return response()->json(['message' => 'Вы не можете деактивировать этого пользователя'], 403);
            }
        }

        if ($user->id === $currentUser->id) {
            return response()->json(['message' => 'Вы не можете деактивировать самого себя'], 403);
        }

        $user->status = 'inactive';
        $user->save();

        // Invalidate user's tokens to log them out
        $user->tokens()->delete();

        return response()->json(['message' => 'Пользователь успешно деактивирован']);
    }
    public function index(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();

        // Owners can see all users, managers only users from their dealership
        $query = ($user->role === Role::OWNER->value)
            ? User::query()
            : User::where('dealership_id', $user->dealership_id);

        $perPage = (int) $request->query('per_page', '15');
        $phone = (string) $request->query('phone', '');

        if ($phone !== '') {
            $normalized = $this->normalizePhone($phone);
            if ($normalized === '') {
                return UserResource::collection(collect([]));
            }

            $driver = config('database.default');
            if ($driver === 'pgsql') {
                $query->whereRaw("regexp_replace(phone, '\\D', '', 'g') LIKE ?", ["%{$normalized}%"]);
            } else {
                $query->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$normalized}%"]);
            }
        }

        $users = $query->orderByDesc('created_at')->paginate($perPage);

        return UserResource::collection($users);
    }

    public function show($id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Пользователь не найден'
            ], 404);
        }

        return new UserResource($user);
    }

    public function status($id)
    {
        $user = User::find($id);

        // Если пользователь не найден или поле active = false → возвращаем is_active = false
        $isActive = $user && ($user->status == 'active');

        return response()->json([
            'is_active' => (bool) $isActive,
        ]);
    }

    /**
     * Нормализует телефон: убирает все не-цифры.
     * Возвращает строку из цифр или пустую строку.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
