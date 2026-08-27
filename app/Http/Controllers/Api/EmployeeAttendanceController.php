<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends ApiController
{
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = $employee->attendances()->orderByDesc('work_date');
        if (!empty($filters['from'])) $query->whereDate('work_date', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('work_date', '<=', $filters['to']);

        $records = $query->get();
        $workedMinutes = (int) $records->sum('worked_minutes');

        return $this->success('Employee attendance fetched', [
            'records' => $records->map(fn (EmployeeAttendance $a) => $this->serialize($a))->values(),
            'summary' => [
                'recorded_days' => $records->count(),
                'present_days' => $records->whereIn('status', ['PRESENT', 'LATE'])->count(),
                'late_days' => $records->where('status', 'LATE')->count(),
                'absent_days' => $records->where('status', 'ABSENT')->count(),
                'leave_days' => $records->where('status', 'LEAVE')->count(),
                'worked_minutes' => $workedMinutes,
                'worked_hours' => round($workedMinutes / 60, 2),
            ],
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', 'in:PRESENT,LATE,ABSENT,LEAVE,DAY_OFF'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $workDate = Carbon::parse($data['work_date'])->startOfDay();
        $checkIn = !empty($data['check_in']) ? Carbon::parse($workDate->toDateString().' '.$data['check_in']) : null;
        $checkOut = !empty($data['check_out']) ? Carbon::parse($workDate->toDateString().' '.$data['check_out']) : null;
        if ($checkIn && $checkOut && $checkOut->lt($checkIn)) {
            $checkOut->addDay(); // دعم دوام يتجاوز منتصف الليل
        }

        $schedule = $employee->workSchedules()->where('day_of_week', $workDate->dayOfWeek)->first();
        $lateMinutes = 0;
        if ($checkIn && $schedule?->is_working_day && $schedule->start_time) {
            $scheduledStart = Carbon::parse($workDate->toDateString().' '.$schedule->start_time);
            $lateMinutes = max(0, $scheduledStart->diffInMinutes($checkIn, false));
        }

        $workedMinutes = 0;
        if ($checkIn && $checkOut) {
            $workedMinutes = max(0, $checkIn->diffInMinutes($checkOut) - (int) ($schedule?->break_minutes ?? 0));
        }

        $status = $data['status'];
        if ($status === 'PRESENT' && $lateMinutes > 0) $status = 'LATE';
        if (in_array($status, ['ABSENT', 'LEAVE', 'DAY_OFF'], true)) {
            $checkIn = null;
            $checkOut = null;
            $workedMinutes = 0;
            $lateMinutes = 0;
        }

        $attendance = EmployeeAttendance::updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $workDate->toDateString()],
            [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'worked_minutes' => $workedMinutes,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()?->id,
            ]
        );

        return $this->success('Attendance saved', $this->serialize($attendance->fresh()), 201);
    }

    public function destroy(Employee $employee, EmployeeAttendance $attendance): JsonResponse
    {
        abort_unless($attendance->employee_id === $employee->id, 404);
        $attendance->delete();
        return $this->success('Attendance deleted', []);
    }

    private function serialize(EmployeeAttendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'work_date' => $attendance->work_date?->toDateString(),
            'check_in' => $attendance->check_in?->format('H:i'),
            'check_out' => $attendance->check_out?->format('H:i'),
            'status' => $attendance->status,
            'late_minutes' => (int) $attendance->late_minutes,
            'worked_minutes' => (int) $attendance->worked_minutes,
            'worked_hours' => round(((int) $attendance->worked_minutes) / 60, 2),
            'notes' => $attendance->notes,
        ];
    }
}
