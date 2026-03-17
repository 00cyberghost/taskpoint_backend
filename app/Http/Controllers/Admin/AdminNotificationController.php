<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminNotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('admin-notifications', [
            'notifications' => Notification::query()
                ->with('user:id,name,email,role')
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'users' => User::query()
                ->whereIn('role', ['client', 'freelancer'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:clients,freelancers,both,individual'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'image', 'max:10240'],
        ]);

        if ($validated['audience'] === 'individual' && empty($validated['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => ['A user must be selected for individual notifications.'],
            ]);
        }

        $users = match ($validated['audience']) {
            'clients' => User::query()->where('role', 'client')->where('status', 'active')->get(),
            'freelancers' => User::query()->where('role', 'freelancer')->where('status', 'active')->get(),
            'both' => User::query()->whereIn('role', ['client', 'freelancer'])->where('status', 'active')->get(),
            'individual' => User::query()->whereKey($validated['user_id'])->get(),
        };

        if ($users->isEmpty()) {
            throw ValidationException::withMessages([
                'audience' => ['No eligible users were found for this notification target.'],
            ]);
        }

        $imagePath = $request->hasFile('image')
            ? $request->file('image')?->store('notification-images', 'public')
            : null;

        $sent = $this->notificationService->broadcast(
            $users,
            'admin_broadcast',
            $validated['title'],
            $validated['body'],
            [
                'audience' => $validated['audience'],
                'sent_by_admin_id' => $request->user()?->id,
            ],
            imagePath: $imagePath,
        );

        return back()->with('success', "Notification queued for {$sent->count()} recipient(s).");
    }
}
