<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current user's own notification feed — what the bell in the CRM shell
 * shows. Every route is scoped to $request->user(): a notification is
 * addressed to one account and only that account can read or clear it, so
 * there is no per-notification permission to check beyond being signed in to
 * the CRM (crm.access, applied on the route group).
 */
class NotificationController extends Controller
{
    /**
     * GET /api/crm/notifications
     *
     * Newest first, paginated, with the unread tally alongside so the bell
     * badge and the list come from one request.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $page = $user->notifications()
            ->latest()
            ->paginate(min(max((int) $request->integer('per_page', 15), 1), 50));

        return response()->json([
            'data' => $page,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/crm/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        abort_if($notification === null, 404, 'الإشعار غير موجود.');

        $notification->markAsRead();

        return response()->json([
            'data' => $notification->fresh(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/crm/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}
