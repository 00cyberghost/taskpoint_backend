<?php

namespace App\Services;

use App\Mail\EmailVerificationOtpMail;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailVerificationOtpService
{
    public function send(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $existingOtp = EmailVerificationOtp::query()->where('user_id', $user->id)->first();

        if ($existingOtp && $existingOtp->sent_at?->gt(now()->subSeconds(60))) {
            throw ValidationException::withMessages([
                'otp' => ['Please wait a minute before requesting another verification code.'],
            ]);
        }

        $otp = (string) random_int(100000, 999999);

        EmailVerificationOtp::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'sent_at' => now(),
            ],
        );

        Mail::to($user->email)->send(new EmailVerificationOtpMail(
            name: $user->name,
            otp: $otp,
        ));
    }

    public function verify(User $user, string $otp): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $record = EmailVerificationOtp::query()->where('user_id', $user->id)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => ['No verification code was found. Please request a new one.'],
            ]);
        }

        if ($record->expires_at->isPast()) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['This verification code has expired. Please request a new one.'],
            ]);
        }

        if ($record->attempts >= 5) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['Too many invalid attempts. Please request a new code.'],
            ]);
        }

        if (! Hash::check($otp, $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'otp' => ['The verification code you entered is invalid.'],
            ]);
        }

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $record->delete();
    }
}
