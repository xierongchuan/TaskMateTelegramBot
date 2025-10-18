<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportantLink;
use Illuminate\Http\Request;
use App\Enums\Role;

class ImportantLinkController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role === Role::OWNER->value) {
            $dealershipId = $request->query('dealership_id');
            $query = $dealershipId ? ImportantLink::where('dealership_id', $dealershipId) : ImportantLink::query();
        } else {
            $query = ImportantLink::where('dealership_id', $user->dealership_id);
        }

        return response()->json($query->orderBy('sort_order')->get());
    }

    public function store(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role !== Role::OWNER->value && $user->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url',
            'description' => 'nullable|string',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'sort_order' => 'integer',
        ]);

        if ($user->role === Role::MANAGER->value && $validated['dealership_id'] !== $user->dealership_id) {
            return response()->json(['message' => 'Вы не можете создавать ссылки для этого салона'], 403);
        }

        $link = ImportantLink::create(array_merge($validated, [
            'creator_id' => $user->id,
            'is_active' => true
        ]));

        return response()->json($link, 201);
    }

    public function update(Request $request, ImportantLink $link)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role !== Role::OWNER->value && $user->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        if ($user->role === Role::MANAGER->value && $link->dealership_id !== $user->dealership_id) {
            return response()->json(['message' => 'Вы не можете редактировать эту ссылку'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|url',
            'description' => 'nullable|string',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $link->update($validated);

        return response()->json($link);
    }

    public function destroy(ImportantLink $link)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role !== Role::OWNER->value && $user->role !== Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        if ($user->role === Role::MANAGER->value && $link->dealership_id !== $user->dealership_id) {
            return response()->json(['message' => 'Вы не можете удалить эту ссылку'], 403);
        }

        $link->delete();

        return response()->json(null, 204);
    }
}
