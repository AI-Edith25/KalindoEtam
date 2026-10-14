<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Reset Password</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;background:#f4f4f5;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td style="font-size:16px;color:#18181b;line-height:1.6;">
                            <p>Halo {{ $userName }},</p>
                            <p>Kami menerima permintaan untuk mengatur ulang password akun Anda. Klik tombol di bawah ini untuk membuat password baru:</p>
                            <p style="text-align:center;margin:32px 0;">
                                <a href="{{ $resetUrl }}" style="background:#18181b;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;">Reset Password</a>
                            </p>
                            <p style="font-size:13px;color:#71717a;">Link ini hanya berlaku selama 60 menit dan hanya bisa digunakan satu kali. Jika Anda tidak meminta reset password, abaikan email ini.</p>
                            <p style="font-size:13px;color:#71717a;word-break:break-all;">Atau salin tautan berikut ke browser Anda: {{ $resetUrl }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
