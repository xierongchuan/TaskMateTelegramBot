<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $request->query('dealership_id');
        $status = $request->query('status');
        $date = $request->query('date');

        $query = Shift::with(['user', 'dealership', 'replacement']);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($date) {
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();
            $query->whereBetween('shift_start', [$startOfDay, $endOfDay]);
        }

        $shifts = $query->orderByDesc('shift_start')->paginate($perPage);

        return response()->json($shifts);
    }

    public function show($id)
    {
        $shift = Shift::with(['user', 'dealership', 'replacement.replacingUser', 'replacement.replacedUser'])
            ->find($id);

        if (!$shift) {
            return response()->json([
                'message' => 'Смена не найдена'
            ], 404);
        }

        return response()->json($shift);
    }

    public function current(Request $request)
    {
        $dealershipId = $request->query('dealership_id');

        $query = Shift::with(['user', 'dealership', 'replacement'])
            ->where('status', 'open')
            ->whereNull('shift_end');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $currentShifts = $query->orderBy('shift_start')->get();

        return response()->json($currentShifts);
    }

    public function statistics(Request $request)
    {
        $dealershipId = $request->query('dealership_id');
        $startDate = $request->query('start_date', Carbon::now()->subDays(7));
        $endDate = $request->query('end_date', Carbon::now());

        $query = Shift::query();

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $query->whereBetween('shift_start', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);

        $totalShifts = $query->count();
        $lateShifts = (clone $query)->where('late_minutes', '>', 0)->count();
        $avgLateMinutes = (clone $query)->where('late_minutes', '>', 0)->avg('late_minutes');
        $replacements = (clone $query)->has('replacement')->count();

        return response()->json([
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            'avg_late_minutes' => round($avgLateMinutes ?? 0, 2),
            'replacements' => $replacements,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ]
        ]);
    }

    /**
     * Get shifts for the authenticated user
     */
    public function my(Request $request)
    {
        $user = $request->user();
        $perPage = (int) $request->query('per_page', '15');
        $status = $request->query('status');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = Shift::with(['user', 'dealership', 'replacement'])
            ->where('user_id', $user->id);

        if ($status) {
            $query->where('status', $status);
        }

        if ($dateFrom) {
            $query->where('shift_start', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo) {
            $query->where('shift_start', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $shifts = $query->orderByDesc('shift_start')->paginate($perPage);

        return response()->json($shifts);
    }

    /**
     * Get the current active shift for the authenticated user
     */
    public function myCurrent(Request $request)
    {
        $user = $request->user();

        $shift = Shift::with(['user', 'dealership', 'replacement'])
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->whereNull('shift_end')
            ->orderByDesc('shift_start')
            ->first();

        if (!$shift) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No active shift found'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => $shift
        ]);
    }
}
