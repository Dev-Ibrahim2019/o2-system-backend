<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeWorkScheduleController extends ApiController
{
    public function index(Employee $employee): JsonResponse
    {
        $rows = $employee->workSchedules()->orderBy('day_of_week')->get();
        $byDay = $rows->keyBy('day_of_week');

        $schedule = collect(range(0, 6))->map(function (int $day) use ($byDay, $employee) {
            $row = $byDay->get($day);
            return [
                'id' => $row?->id,
                'employee_id' => $employee->id,
                'day_of_week' => $day,
                'is_working_day' => (bool) ($row?->is_working_day ?? false),
                'start_time' => $row?->start_time ? substr($row->start_time, 0, 5) : null,
                'end_time' => $row?->end_time ? substr($row->end_time, 0, 5) : null,
                'break_minutes' => (int) ($row?->break_minutes ?? 0),
                'notes' => $row?->notes,
            ];
        });

        return $this->success('Employee work schedule fetched', [
            'schedule' => $schedule,
            'working_days_per_week' => $schedule->where('is_working_day', true)->count(),
            'scheduled_hours_per_week' => round($this->scheduledMinutes($schedule->all()) / 60, 2),
        ]);
    }

    public function upsert(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'schedule' => ['required', 'array', 'size:7'],
            'schedule.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'schedule.*.is_working_day' => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'date_format:H:i'],
            'schedule.*.end_time' => ['nullable', 'date_format:H:i'],
            'schedule.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'schedule.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $employee) {
            foreach ($data['schedule'] as $row) {
                $working = (bool) $row['is_working_day'];
                if ($working && (empty($row['start_time']) || empty($row['end_time']))) {
                    abort(422, 'وقت البداية والنهاية مطلوبان في يوم العمل');
                }

                $employee->workSchedules()->updateOrCreate(
                    ['day_of_week' => (int) $row['day_of_week']],
                    [
                        'branch_id' => $employee->branch_id,
                        'is_working_day' => $working,
                        'start_time' => $working ? $row['start_time'] : null,
                        'end_time' => $working ? $row['end_time'] : null,
                        'break_minutes' => $working ? (int) ($row['break_minutes'] ?? 0) : 0,
                        'notes' => $row['notes'] ?? null,
                    ]
                );
            }
        });

        return $this->index($employee);
    }

    private function scheduledMinutes(array $schedule): int
    {
        $total = 0;
        foreach ($schedule as $row) {
            if (!$row['is_working_day'] || !$row['start_time'] || !$row['end_time']) continue;
            $start = \Carbon\Carbon::createFromFormat('H:i', $row['start_time']);
            $end = \Carbon\Carbon::createFromFormat('H:i', $row['end_time']);
            if ($end->lte($start)) $end->addDay();
            $total += max(0, $start->diffInMinutes($end) - (int) ($row['break_minutes'] ?? 0));
        }
        return $total;
    }
}
