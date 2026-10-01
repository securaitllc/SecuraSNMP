<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; -webkit-font-smoothing:antialiased; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,.08);">

          <!-- Brand bar -->
          <tr>
            <td style="background:#0f172a; padding:18px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:.2px;">
                    Nodus
                    <span style="color:#94a3b8; font-size:12px; font-weight:500; letter-spacing:.4px; text-transform:uppercase;">&nbsp;· Network Monitoring</span>
                  </td>
                  <td align="right" style="color:#64748b; font-size:12px;">{{ $sentAt }}</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Status header -->
          <tr>
            <td style="background:{{ $accent }}; padding:20px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="color:#ffffff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.6px;">
                    {{ $statusLabel }}
                  </td>
                  <td align="right">
                    <span style="display:inline-block; background:rgba(255,255,255,.22); color:#ffffff; font-size:11px; font-weight:700; letter-spacing:.5px; padding:4px 10px; border-radius:999px;">
                      {{ $severityLabel }}
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Subject / title -->
          <tr>
            <td style="padding:26px 28px 8px 28px;">
              <div style="color:#0f172a; font-size:20px; font-weight:700; line-height:1.35;">{{ $subjectLine }}</div>
            </td>
          </tr>

          <!-- Description details -->
          <tr>
            <td style="padding:8px 28px 8px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:10px; border-collapse:separate; overflow:hidden;">
                @foreach ($rows as $row)
                  <tr>
                    @if ($row['label'])
                      <td style="padding:11px 16px; background:#f8fafc; color:#64748b; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; width:38%; border-bottom:1px solid #eef2f7; vertical-align:top;">{{ $row['label'] }}</td>
                      <td style="padding:11px 16px; color:#0f172a; font-size:14px; border-bottom:1px solid #eef2f7; vertical-align:top;">{{ $row['value'] }}</td>
                    @else
                      <td colspan="2" style="padding:11px 16px; color:#334155; font-size:14px; line-height:1.5; border-bottom:1px solid #eef2f7;">{{ $row['value'] }}</td>
                    @endif
                  </tr>
                @endforeach
              </table>
            </td>
          </tr>

          <!-- Guidance -->
          <tr>
            <td style="padding:16px 28px 26px 28px;">
              <div style="color:#64748b; font-size:13px; line-height:1.55;">
                @if ($resolved)
                  This condition has cleared. No action is required — this message is for your records.
                @else
                  Please review the affected device or circuit in the Nodus console. This alert will send a follow-up when the condition clears.
                @endif
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f8fafc; padding:16px 28px; border-top:1px solid #e2e8f0;">
              <div style="color:#94a3b8; font-size:12px; line-height:1.5;">
                Nodus network monitoring — automated alert.
                You are receiving this because this address is a configured notification channel.
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
