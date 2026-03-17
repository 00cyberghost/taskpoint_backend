<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin-users', [
            'users' => User::query()
                ->with([
                    'clientProfile:id,user_id,verification_status',
                    'freelancerProfile:id,user_id,verification_status,payout_status,success_rate,total_completed',
                ])
                ->latest()
                ->paginate(15)
                ->withQueryString(),
        ]);
    }
}
