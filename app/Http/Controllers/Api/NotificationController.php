<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F19 — the signed-in user's in-app notification inbox.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()->latest()->limit(30)->get()->map(fn ($n): array => [
            'id' => $n->id,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
            ...$n->data,
        ]);

        return response()->json([
            'data' => $items,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }
}
