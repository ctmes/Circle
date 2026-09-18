{{--
    The one email template.

    Plain, narrow and ink-on-paper, because this is a controlled document and
    not a newsletter. No images, no tracking pixel, no unsubscribe footer that
    implies there is a marketing list somewhere — there is not, and every
    message sent by this system is one somebody's work is waiting on.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $kind->subject($circleName) }}</title>
</head>
<body style="margin:0; padding:0; background:#eceeee;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceeee; padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border:1px solid #c3ccd5; font-family:Georgia,'Times New Roman',serif; color:#131a20;">

        <tr>
          <td style="padding:18px 24px 10px; border-bottom:1px solid #dce2e8;">
            <div style="font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:#68777a;">
              {{ $circleName }}
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:20px 24px 4px;">
            <h1 style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:20px; line-height:1.3; font-weight:700; color:#131a20;">
              {{ $headline }}
            </h1>
          </td>
        </tr>

        @if (! empty($facts))
        <tr>
          <td style="padding:14px 24px 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              @foreach ($facts as $label => $value)
              <tr>
                <td style="padding:6px 10px 6px 0; vertical-align:top; white-space:nowrap; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:10px; letter-spacing:.1em; text-transform:uppercase; color:#68777a; border-bottom:1px solid #eef2f4;">
                  {{ $label }}
                </td>
                <td style="padding:6px 0; vertical-align:top; font-size:15px; line-height:1.45; color:#3a4749; border-bottom:1px solid #eef2f4;">
                  {{ $value }}
                </td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @endif

        <tr>
          <td style="padding:22px 24px 6px;">
            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 18px; background:#12456b; color:#ffffff; text-decoration:none; font-family:Helvetica,Arial,sans-serif; font-size:14px; font-weight:600;">
              {{ $actionLabel }}
            </a>
          </td>
        </tr>

        @if ($note)
        <tr>
          <td style="padding:10px 24px 0;">
            <p style="margin:0; font-size:14px; line-height:1.55; color:#3a4749; border-left:3px solid #12456b; padding-left:12px;">
              {{ $note }}
            </p>
          </td>
        </tr>
        @endif

        <tr>
          <td style="padding:22px 24px 20px;">
            <p style="margin:0; font-size:12px; line-height:1.55; color:#68777a; border-top:1px solid #dce2e8; padding-top:12px;">
              You are receiving this because your work in this Circle is waiting on it. Circle sends nothing else &mdash; there is no digest, no reminder and no list to leave.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
