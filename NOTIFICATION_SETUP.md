# Notification delivery setup

The portal uses the same SMTP/Twilio transport configuration in local WAMP and online hosting. Keep credentials out of PHP source control. Set the variables in the Apache/PHP service environment locally and in the hosting provider's environment-variable or secret settings online, then restart/redeploy PHP.

On Windows WAMP, local SMTP values can instead be read from `C:\wamp64\private\sps-notifications.ini`, which is outside the web root and workspace. That file takes effect only when the corresponding process environment variable is unset. After creating a new Gmail App Password, run [scripts/set_local_smtp_app_password.ps1](scripts/set_local_smtp_app_password.ps1) in PowerShell; it prompts without echoing the secret and writes it to the private INI. The INI file must remain untracked and readable only by the local account and Apache service account. Apache currently runs as `LocalSystem`, which has access to this protected file.

## Email (SMTP)

Install the declared PHP dependency in each deployment with `composer install` (or deploy the generated `vendor` directory with the application):

- `SPS_SMTP_HOST` — SMTP hostname.
- `SPS_SMTP_PORT` — normally `587` for TLS or `465` for SSL.
- `SPS_SMTP_USERNAME` and `SPS_SMTP_PASSWORD` — SMTP credentials; omit authentication only if the SMTP server explicitly permits it.
- `SPS_SMTP_ENCRYPTION` — `tls`, `ssl`, or `none` (use `none` only on a trusted local relay).
- `SPS_MAIL_FROM` — verified sender email address.
- `SPS_MAIL_FROM_NAME` — optional sender label; defaults to `Service Portal`.
- `SPS_PUBLIC_BASE_URL` — portal origin and path, e.g. `http://localhost/sps` locally and `https://portal.example.com/sps` online. This makes links in messages absolute.

Configure the local and online values independently. Do not use `.local` as the public sender address; most mail providers will reject it. An SMTP `accepted` result means the SMTP server accepted the message, not that it reached the recipient's inbox.

For Gmail SMTP, use `smtp.gmail.com`, port `587`, encryption `tls`, and the full Gmail address as both username and verified sender. Use a Google App Password with 2-Step Verification—not the normal account password.

## Twilio SMS

- `SPS_TWILIO_ACCOUNT_SID`
- `SPS_TWILIO_AUTH_TOKEN` — secret; never commit or expose it.
- Configure either `SPS_TWILIO_SMS_MESSAGING_SERVICE_SID` or `SPS_TWILIO_SMS_FROM`.
- Customer/staff destination numbers must be stored in E.164 format, such as `+14155552671`.

## Twilio WhatsApp

- Use the same account SID and auth token as SMS.
- `SPS_TWILIO_WHATSAPP_FROM` — Twilio-approved sender number; accepts `+...` or `whatsapp:+...`.
- `SPS_TWILIO_WHATSAPP_CONTENT_SID` — recommended for business-initiated messages outside the customer-service window. The approved template must provide variables `{{1}}` (subject), `{{2}}` (message), and `{{3}}` (portal link), in that order. Without this setting, the portal sends a normal WhatsApp body, which Twilio may reject outside an active session.

## Routing and fallback

- No enabled channels means no external message; the in-app notification bell remains available.
- A selected SMS/WhatsApp channel is tried first. If it is not configured or the provider rejects the request immediately, email is attempted if email is enabled.
- A Twilio `accepted` response means queued/accepted by Twilio, not final handset delivery, so the portal does not immediately send a duplicate email fallback. Asynchronous delivery-status callbacks are not yet implemented.
- `All enabled channels` attempts each enabled channel.

## Staff profile settings

Admins, office users, staff, and technicians share the authenticated **My Profile** page. Each person can update their own name/email, change their password, choose email/SMS/WhatsApp preferences, and save their own international-format SMS and WhatsApp numbers. Provider credentials remain administrator/server configuration; they are not entered on individual profiles. Customers configure their own channel preferences from Customer Profile.

## Local testing and deployment

Configure provider trial/sandbox restrictions first. Local WAMP can make outbound API/SMTP requests if those services are reachable; inbound provider callbacks to `localhost` will not work without a secure public tunnel. The online portal needs its own environment variables and a public HTTPS URL. The portal logs channel outcomes in `notification_log` (staff) and `customer_notification_log` (customers); never log provider passwords or tokens.