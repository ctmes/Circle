{{--
    Deliberately the plainest thing this system sends.

    It names no Circle, no party and no colleague, because it goes to an address
    on the strength of somebody typing it into a form. If it reached the wrong
    inbox it should tell that inbox nothing at all.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset your Circle password</title>
</head>
<body style="margin:0; padding:0; background:#eceeee;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceeee; padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#ffffff; border:1px solid #c3ccd5; font-family:Georgia,'Times New Roman',serif; color:#131a20;">

        <tr>
          <td style="padding:18px 24px 10px; border-bottom:1px solid #dce2e8;">
            <div style="font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:#68777a;">
              Circle
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:20px 24px 0;">
            <h1 style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:19px; line-height:1.3; font-weight:700;">
              Set a new password
            </h1>
            <p style="margin:12px 0 0; font-size:15px; line-height:1.55; color:#3a4749;">
              Somebody asked to reset the password for this address. If that was not you, nothing has changed and you can ignore this.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:20px 24px 6px;">
            <a href="{{ $resetUrl }}" style="display:inline-block; padding:10px 18px; background:#12456b; color:#ffffff; text-decoration:none; font-family:Helvetica,Arial,sans-serif; font-size:14px; font-weight:600;">
              Choose a new password
            </a>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 24px 20px;">
            <p style="margin:0; font-size:12px; line-height:1.55; color:#68777a; border-top:1px solid #dce2e8; padding-top:12px;">
              This link works once and expires in {{ $expiresMinutes }} minutes. Signing in with a new password ends every other session on your account.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
