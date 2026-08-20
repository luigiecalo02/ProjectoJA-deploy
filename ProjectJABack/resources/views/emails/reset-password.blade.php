<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restablecer contraseña</title>
</head>
<body style="margin:0;padding:0;background:#dce6f4;font-family:Georgia,'Times New Roman',serif;">
@php
    $heroCid = (! empty($heroPath) && is_file($heroPath)) ? $message->embed($heroPath) : null;
    $patternCid = (! empty($patternPath) && is_file($patternPath)) ? $message->embed($patternPath) : null;
    $logoCid = (! empty($logoPath) && is_file($logoPath)) ? $message->embed($logoPath) : null;
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#dce6f4;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(11,47,107,0.16);">
                <tr>
                    <td style="background:#0b2f6b;height:168px;">
                        @if ($heroCid)
                            <img src="{{ $heroCid }}" alt="ProjectJA" width="600" style="display:block;width:100%;max-height:200px;object-fit:cover;border:0;">
                        @else
                            <div style="padding:48px 28px;color:#ffcc00;font-family:Arial,Helvetica,sans-serif;">
                                <p style="margin:0 0 6px;font-size:13px;letter-spacing:2px;text-transform:uppercase;">ProjectJA</p>
                                <h1 style="margin:0;font-size:28px;line-height:1.15;">{{ $line1 }} {{ $line2 }}</h1>
                            </div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td
                        bgcolor="#f7f4ec"
                        @if ($patternCid)
                            background="{{ $patternCid }}"
                            style="background-color:#f7f4ec;background-image:url('{{ $patternCid }}');background-repeat:repeat;padding:36px 32px 28px;"
                        @else
                            style="background-color:#f7f4ec;padding:36px 32px 28px;"
                        @endif
                    >
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="center" style="padding-bottom:18px;">
                                    @if ($logoCid)
                                        <img src="{{ $logoCid }}" alt="ProjectJA" width="96" style="display:block;width:96px;height:auto;border:0;">
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td align="center" style="padding-bottom:22px;font-family:Arial,Helvetica,sans-serif;">
                                    <p style="margin:0 0 4px;color:#0b2f6b;font-size:13px;letter-spacing:1.4px;text-transform:uppercase;font-weight:700;">{{ $line1 }}</p>
                                    <h2 style="margin:0 0 8px;color:#0b2f6b;font-size:26px;line-height:1.2;">{{ $line2 }}</h2>
                                    <p style="margin:0;color:#4b5b76;font-size:14px;line-height:1.5;max-width:460px;">{{ $subtitle }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="background:#ffffff;border:1px solid #eadfc4;border-radius:14px;padding:26px 24px;font-family:Arial,Helvetica,sans-serif;color:#1f2a3d;">
                                    <p style="margin:0 0 14px;font-size:20px;color:#0b2f6b;font-weight:700;">¡Hola{{ $userName ? ', '.$userName : '' }}!</p>
                                    <p style="margin:0 0 22px;font-size:15px;line-height:1.65;">
                                        Recibes este correo electrónico porque hemos recibido una solicitud de restablecimiento de contraseña para tu cuenta.
                                    </p>
                                    <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 22px;">
                                        <tr>
                                            <td align="center" bgcolor="#0b2f6b" style="border-radius:999px;background:#0b2f6b;">
                                                <a href="{{ $url }}" style="display:inline-block;padding:14px 28px;color:#ffcc00;font-size:15px;font-weight:700;text-decoration:none;letter-spacing:0.3px;">
                                                    Restablecer contraseña
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#4b5b76;">
                                        Este enlace para restablecer la contraseña caducará en {{ $expire }} minutos.
                                        Si no solicitó un restablecimiento de contraseña, no es necesario realizar ninguna otra acción.
                                    </p>
                                    <p style="margin:0;font-size:15px;line-height:1.6;color:#0b2f6b;">
                                        Saludos,<br>
                                        <strong>ProjectJA</strong>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding-top:20px;font-family:Arial,Helvetica,sans-serif;color:#5b6b82;font-size:12px;line-height:1.6;">
                                    Si tiene problemas para hacer clic en el botón "Restablecer contraseña", copie y pegue la siguiente URL en su navegador web:
                                    <br>
                                    <a href="{{ $url }}" style="color:#0b2f6b;word-break:break-all;">{{ $url }}</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background:#0b2f6b;padding:14px 24px;text-align:center;font-family:Arial,Helvetica,sans-serif;color:#ffcc00;font-size:12px;">
                        ProjectJA · Clubes de Conquistadores, Aventureros y Guías Mayores
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
