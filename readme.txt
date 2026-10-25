=== Demfilz SMTP Mailer ===
Contributors: demfilz
Tags: smtp, email, mailer, email log, deliverability
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Microsoft 365, Google or any SMTP service for WordPress email, with bounce capture and a suppression list so dead addresses are never emailed twice.

== Description ==

WordPress hands its email to the PHP mail function, which is unauthenticated and unreliable. Order confirmations, password resets and contact form messages go missing or land in spam.

Demfilz SMTP Mailer routes every message your site sends through a mail service that will vouch for it, then keeps a record of what happened.

**Microsoft mailboxes are first class here**

Microsoft has switched basic authentication off across Exchange Online, so plugins that only offer a user name and password no longer work for most tenants. This plugin gives you three ways in:

* **Graph API with a signed in mailbox.** Register an application, press Connect, sign in once. Works even when SMTP AUTH is disabled on the mailbox.
* **Graph API with application permissions.** No sign in at all. An administrator consents once, and the site sends as the mailbox you nominate. Pair it with an application access policy so the site can only use that one mailbox.
* **SMTP with modern authentication.** Ordinary SMTP to smtp.office365.com, but signed in with OAuth instead of a password.

Personal Outlook.com and Hotmail mailboxes work through the same Graph API mailer.

**It finds out what happened after the send**

Most mail plugins stop at "accepted by the server". This one closes the loop:

* **Bounce capture.** For Microsoft and Google it reads the delivery reports that come back to the connected mailbox and parses the machine readable part, so a log entry flips from Delivered to Bounced with the real reason, such as 5.1.1 the address does not exist. For SendGrid, Mailgun, Brevo, Postmark and Resend it takes their delivery, bounce and complaint webhooks.
* **A suppression list.** Hard bounces and spam complaints go on it, and the plugin refuses to send to those addresses again. Repeatedly emailing dead addresses is what gets a domain throttled and blocklisted; this is the part that protects your sending reputation.
* **Delivery confirmation.** When a service confirms delivery, the log entry says so.

**Every mailer included**

* Microsoft 365, Exchange Online, Outlook.com and Hotmail
* Google Workspace and Gmail
* Any other SMTP server, with STARTTLS or SSL
* SendGrid
* Mailgun, United States or European region
* Brevo
* Postmark
* Resend
* The default PHP mail function, if you want the log and the alerts without changing how mail is sent

**More than a connection**

* **Email log.** Every message, who it went to, which mailer carried it, and the exact reason for any failure. Search it, filter it, download it as CSV, and open a single entry to see the headers.
* **Suppression list.** Addresses that bounced or complained, plus any you add by hand, with search, CSV export and one click removal. A message to a suppressed address alone is recorded as blocked; other recipients still get theirs.
* **Automatic retries.** A failed message is queued and tried again in the background, with the wait doubling each time.
* **Backup mailer.** When the first mailer fails, the finished message is handed straight to a second one, so nothing is lost while a provider is down.
* **Failure alerts.** A dashboard notice always, plus an optional email and a JSON webhook for your chat channel. Optional weekly summary.
* **Domain records check.** Looks up SPF, DKIM and DMARC for your sending domain and tells you what is missing, with the values your chosen mailer needs.
* **Email controls.** Switch off individual WordPress notifications, such as the new user emails or the automatic update reports.
* **Test email tool.** Sends a real message and shows you the whole conversation with the mail server when it fails.
* **Import.** Picks up settings left behind by WP Mail SMTP, Easy WP SMTP or Post SMTP.

**Built carefully**

* Passwords, API keys and OAuth tokens are encrypted with your site salts before they are stored, and are never printed back into the settings screen.
* Any setting can be pinned in wp-config.php instead of the database.
* The sign in flow is bound to a single use value tied to the administrator who started it.
* The from address is corrected automatically when a provider only accepts its own mailbox, which is the usual cause of "cannot send as this user" errors.
* Multisite aware. Network activate it and one set of credentials serves every site.
* Privacy exporter and eraser for the email log.
* No account with us and nothing of ours in the middle. Your site talks straight to the mail provider you chose, and the plugin never sends your data anywhere else. Every service it can contact is listed under External services below.

== Installation ==

1. Install and activate the plugin.
2. Go to Mail > Settings, set the from address, and choose a mailer.
3. Fill in the connection details for that mailer and save.
4. Go to Mail > Tools and send yourself a test email.

**Microsoft 365 and Outlook, signing in to one mailbox**

1. In the Microsoft Entra admin centre, open App registrations and create a new registration.
2. Add a Web platform with the redirect URI shown on the plugin settings screen.
3. Under Certificates and secrets, create a client secret and copy the Value.
4. Paste the application id, the secret and your directory id into the plugin, save, then press Connect and sign in.

**Microsoft 365 with application permissions**

1. Register the application as above, but skip the sign in.
2. Under API permissions add the application permission Mail.Send for Microsoft Graph, then grant admin consent.
3. In the plugin choose application permissions, enter your directory id and the mailbox to send from, and save.
4. In Exchange Online, create an application access policy so the application can only send from that mailbox.

**Google Workspace and Gmail**

1. In the Google Cloud console create a project and enable the Gmail API.
2. Configure the consent screen, then create an OAuth client id of type Web application using the redirect URI shown in the plugin.
3. Paste the client id and secret into the plugin, save, then press Connect.

**Turning on delivery feedback**

1. Under Mail > Settings > Log and alerts, decide whether hard bounces and spam complaints should suppress the address (both are on by default).
2. Microsoft or Google: tick Read bounce reports from the connected mailbox, then disconnect and connect the mailer again so it can grant the read permission (Mail.Read for Microsoft, gmail.readonly for Google).
3. SendGrid, Mailgun, Brevo, Postmark or Resend: copy the webhook address shown on the same screen into the service's webhook settings, for bounce, dropped, spam complaint and delivered events.

== Frequently Asked Questions ==

= How does it know an email bounced? =

Two ways. The sending services (SendGrid, Mailgun, Brevo, Postmark, Resend) post delivery events to a webhook address the plugin gives you, protected by a long secret. Microsoft and Google do not post events, so if you allow it the plugin reads the delivery reports that arrive in the connected mailbox every fifteen minutes and parses the standard delivery status part inside them. Either way the matching log entry is marked Bounced with the reason.

= What goes on the suppression list, and can I take an address off it? =

Hard bounces and spam complaints, when those options are on, plus anything you add by hand. Open Mail > Suppression list to search it, download it, or remove an address, after which it can be emailed again. Temporary problems such as a full mailbox are noted on the log entry but never suppress anyone.

= Does reading bounce reports mean the plugin can read all my mail? =

The permission it asks for (Mail.Read or gmail.readonly) allows reading the mailbox, so technically yes, which is why it is off by default and a separate switch. The plugin only lists recent messages from postmaster or mailer-daemon addresses or with an Undeliverable style subject, fetches only those, and stores only the failed address and the reason. With Microsoft application permissions you can additionally scope the application to one mailbox with an application access policy.

= Do I need a paid mail service? =

No. If you already have a Microsoft 365 or Google Workspace mailbox, that is enough, and it is usually the best choice because the mailbox already passes the checks inboxes make. Sending services are worth it for high volume or when you want detailed delivery reporting.

= My host blocks SMTP ports. Will this help? =

Yes. The Microsoft, Google, SendGrid, Mailgun, Brevo, Postmark and Resend mailers all send over HTTPS, so a blocked port 587 or 465 does not stop them.

= Microsoft says the client does not have permission to send as this user. What now? =

The from address does not match the mailbox you connected. The plugin rewrites the from address to the connected mailbox automatically and moves the original into reply to, so make sure the mailbox setting is filled in. With application permissions, check that the application access policy covers that mailbox.

= Where are my credentials stored? =

Encrypted in the database, using a key derived from the salts in your wp-config.php file, so a stolen database dump alone does not hand them over. You can also define any setting as a constant in wp-config.php, in which case nothing is stored at all.

= Can I keep the log without storing the message bodies? =

Yes, and that is the default. Bodies are only stored if you tick the option, because password reset links and customer details would otherwise sit in your database.

= Does the log grow forever? =

No. Entries older than the retention period, thirty days out of the box, are deleted daily. You can also empty the log by hand at any time.

= What happens when a message fails? =

It is recorded with the exact error, queued, and tried again in the background, up to the number of attempts you set. If you have a backup mailer, the message is handed to it immediately instead. A notice appears in the dashboard either way.

= Will it clash with another SMTP plugin? =

Only one plugin should own sending. This one steps aside if it sees another mail plugin has already taken over, and shows a warning so you know to deactivate one of them.

= Does it work on multisite? =

Yes. Network activate it and the settings, credentials and log are shared across the network. Activate it per site instead if each site sends as itself.

== Screenshots ==

1. Choosing a mailer, with the Microsoft connection panel open.
2. The from address and sender controls.
3. The email log, with delivered, failed and queued messages.
4. A single log entry for a bounced message, with the reason the provider gave and the headers.
5. The test email tool with the conversation from the mail server.
6. The domain records check for SPF, DKIM and DMARC.
7. The suppression list, with a bounced address and the reason the provider gave.

== External services ==

This plugin sends your outgoing email through whichever mail service you choose in its settings. Nothing is sent anywhere until you pick a mailer and enter your own credentials for it, and no data is ever sent to Demfilz.

Depending on the mailer you select, the plugin contacts:

* **Microsoft identity platform and Microsoft Graph** (login.microsoftonline.com, graph.microsoft.com), used by the Microsoft 365 mailers. Sign in details you approve are exchanged for an access token, and each outgoing message (sender, recipients, subject, body and attachments) is posted to Graph for delivery. If you switch on reading bounce reports, the plugin also lists recent messages in the mailbox and fetches the ones that look like delivery reports. Terms: https://www.microsoft.com/licensing/terms/ Privacy: https://privacy.microsoft.com/privacystatement
* **Microsoft Exchange Online SMTP** (smtp.office365.com by default), used by the Microsoft 365 SMTP mailer, with the access token above. Same terms and privacy policy.
* **Google** (accounts.google.com, oauth2.googleapis.com, openidconnect.googleapis.com, gmail.googleapis.com), used by the Google Workspace and Gmail mailer. Sign in details you approve are exchanged for an access token, your address is read once to fill in the mailbox field, and each outgoing message is posted to the Gmail API for delivery. If you switch on reading bounce reports, the plugin also searches the mailbox for messages from mailer-daemon and fetches those. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy
* **SendGrid** (api.sendgrid.com), used by the SendGrid mailer. Each outgoing message is posted there with your API key. Terms: https://www.twilio.com/en-us/legal/tos Privacy: https://www.twilio.com/en-us/legal/privacy
* **Mailgun** (api.mailgun.net, or api.eu.mailgun.net in the European region), used by the Mailgun mailer. Each outgoing message is posted there with your API key. Terms: https://www.mailgun.com/legal/terms/ Privacy: https://www.mailgun.com/legal/privacy-policy/
* **Brevo** (api.brevo.com), used by the Brevo mailer. Each outgoing message is posted there with your API key. Terms: https://www.brevo.com/legal/termsofuse/ Privacy: https://www.brevo.com/legal/privacypolicy/
* **Postmark** (api.postmarkapp.com), used by the Postmark mailer. Each outgoing message is posted there with your server token. Terms: https://postmarkapp.com/terms-of-service Privacy: https://postmarkapp.com/privacy-policy
* **Resend** (api.resend.com), used by the Resend mailer. Each outgoing message is posted there with your API key. Terms: https://resend.com/legal/terms-of-service Privacy: https://resend.com/legal/privacy-policy
* **Any SMTP server you enter yourself**, used by the Other SMTP mailer. The message and your credentials go to the host you configure, and nowhere else.
* **Google Public DNS** (dns.google), used only when you press Check my domain on the Tools screen and only on hosts where PHP cannot look records up itself. The plugin sends the domain name of your from address to read its public SPF, DKIM, DMARC and MX records. Terms: https://policies.google.com/terms Privacy: https://developers.google.com/speed/public-dns/privacy
* **A webhook address you enter yourself**, if you switch failure alerts on and fill one in. The plugin posts the details of a failed or bounced message to that address.

The sending services above can also call **your site** (a REST endpoint the plugin registers under /wp-json/dsmtp/v1/events/) to report deliveries, bounces and complaints, but only if you paste the plugin's webhook address into that service yourself.

== Changelog ==

= 1.1.0 =
* New: bounce capture. Delivery reports are read from a connected Microsoft or Google mailbox (optional, needs read permission) and delivery events are accepted from SendGrid, Mailgun, Brevo, Postmark and Resend webhooks. Matching log entries are marked Bounced with the provider's reason.
* New: suppression list. Hard bounces and spam complaints stop the address being emailed again, with a screen to search, export and remove entries and to add addresses by hand.
* New: delivery confirmations from the sending services are recorded on the log entry.
* New: bounce and complaint alerts by email and webhook.
* Fixed: scheduling of background tasks now waits for init so translations load at the right time.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Adds bounce capture and a suppression list. Nothing changes until you turn delivery feedback on under Log and alerts.

= 1.0.0 =
First release.
