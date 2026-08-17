<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskPoint Verification Code</title>
</head>
<body style="margin:0;padding:0;background:#f7f2ef;font-family:Arial,Helvetica,sans-serif;color:#23110d;">
    <div style="max-width:560px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border-radius:24px;padding:32px;border:1px solid #eadfd7;">
            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Hello {{ $name }},</p>
            <p style="margin:0 0 20px;font-size:16px;line-height:1.6;">
                Use the verification code below to confirm your email address and continue using TaskPoint.
            </p>
            <div style="margin:24px 0;padding:20px 16px;border-radius:18px;background:#ff5a2f;color:#ffffff;text-align:center;font-size:32px;font-weight:700;letter-spacing:10px;">
                {{ $otp }}
            </div>
            <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#6b5a53;">
                This code expires in 10 minutes. If you did not request this code, you can ignore this email.
            </p>
            <p style="margin:20px 0 0;font-size:13px;line-height:1.7;color:#9b8a83;">
                TaskPoint Security Team
            </p>
        </div>
    </div>
</body>
</html>
