<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\CallTicket;
use App\Models\EmployeeBreakSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeBreakController extends ApiController
{
    public function today(Request $request): JsonResponse
    {
        $rows = EmployeeBreakSession::where('user_id', $request->user()->id)->whereDate('started_at', today())->oldest('started_at')->get();
        $data = $rows->map(fn ($row) => $this->serialize($row));
        $seconds = $data->sum('duration_seconds');
        return $this->success('تم تحميل استراحات اليوم', ['breaks_count' => $rows->count(), 'total_duration_seconds' => $seconds, 'total_duration_label' => $this->duration($seconds), 'breaks' => $data]);
    }

    public function start(Request $request): JsonResponse
    {
        $input = $request->validate(['break_type' => 'required|string|max:64', 'reason' => 'nullable|string|max:500']);
        $user = $request->user();
        if (CallTicket::where('agent_id', $user->id)->whereIn('status', ['ringing', 'answered', 'in_progress'])->exists()) throw ValidationException::withMessages(['break' => 'لا يمكن بدء استراحة أثناء مكالمة نشطة.']);
        if (EmployeeBreakSession::where('user_id', $user->id)->where('status', 'active')->exists()) throw ValidationException::withMessages(['break' => 'يوجد استراحة مفتوحة بالفعل.']);
        $row = EmployeeBreakSession::create(['user_id' => $user->id, 'branch_id' => $user->branch_id, 'break_type' => $input['break_type'], 'reason' => $input['reason'] ?? null, 'status' => 'active', 'started_at' => now()]);
        return $this->success('تم بدء الاستراحة', $this->serialize($row), 201);
    }

    public function end(Request $request, EmployeeBreakSession $break): JsonResponse
    {
        abort_unless($break->user_id === $request->user()->id, 403);
        if ($break->status !== 'active') throw ValidationException::withMessages(['break' => 'الاستراحة منتهية بالفعل.']);
        $ended = now();
        $break->update(['status' => 'ended', 'ended_at' => $ended, 'duration_seconds' => max(0, $break->started_at->diffInSeconds($ended))]);
        return $this->success('تم إنهاء الاستراحة', $this->serialize($break->fresh()));
    }

    private function serialize(EmployeeBreakSession $row): array
    {
        $seconds = $row->status === 'active' ? max(0, $row->started_at->diffInSeconds(now())) : (int) $row->duration_seconds;
        $labels = ['regular' => 'استراحة عادية', 'prayer' => 'صلاة', 'meal' => 'طعام', 'emergency' => 'طارئة'];
        return ['id' => $row->id, 'type' => $row->break_type, 'type_label' => $labels[$row->break_type] ?? $row->break_type, 'status' => $row->status, 'started_at' => $row->started_at?->toIso8601String(), 'ended_at' => $row->ended_at?->toIso8601String(), 'duration_seconds' => $seconds, 'duration_label' => $this->duration($seconds), 'reason' => $row->reason];
    }
    private function duration(int $seconds): string { return intdiv($seconds, 60).' د '.($seconds % 60).' ث'; }
}
