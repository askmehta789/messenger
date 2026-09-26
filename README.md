# Messenger Lead Bot — phone-number capture for Facebook Page messages

Every Messenger enquiry gets one answer: **send your mobile number and our team will call you.** The bot never gives prices, delivery charges, discounts or product claims. It checks that the number is a real Nepal mobile, saves a lead, and shows it on a phone-friendly board where the sales team can tap to call.

Stack: PHP 8 + MariaDB, runs on cPanel shared hosting. No framework and no Composer.

---

## 1. What exists today (checked 25 Sep 2026, Page "Nabhi Oil Nepal", asset 1302900159578083)

| Business Suite automation | Status | What it does |
|---|---|---|
| Leads | **On** | Creates a lead activity in Business Suite. Harmless, can stay on. |
| Away message | **On** | Marked "away" 24/7 so it acts as a greeting that asks for the number. It can't check numbers and it replies again and again. |
| Contact information | **On** | Asks for the phone number "to confirm the order". Only fires after 30 min of inactivity. |
| Auto-reply, FAQ, Identify unanswered | Off | – |

There was no custom webhook or code. Native automations can't do what you asked for. They can't check a number, can't remember that a customer already sent one (Flow 7), can't save the lead with its context, and can't limit repeat replies. That's why this project exists.

> An earlier plan for this inbox used AI replies that quoted product prices. That plan is replaced. This bot has **no** price data at all.

## 2. How it works

```
Customer ──► Messenger ──► Meta webhook ──► webhook.php
                                              │ verify signature, dedupe message id
                                              │ lock the conversation row
                                              │ engine.php decides (Flows 1–7)
                                              ├─► mb_leads (name, PSID, phone, ad + messages, time, status)
                                              └─► Send API reply (+ native "share phone number" button)
Sales team ──► admin/ (lead board: Call / WhatsApp / Viber, status, notes, CSV)
Team replies in Business Suite Inbox ──► echo event ──► bot pauses for that customer
```

| Situation | Bot reply |
|---|---|
| First message of any kind | Flow 1 greeting that asks for the number |
| Price or product question, a screenshot, anything else | Flow 2 |
| "call me" / "फोन गर्नुहोस्" … | Flow 6 |
| Valid number (typed, or tapped with Messenger's native phone button) | Flow 4 + lead saved |
| Something that looks like a number but isn't valid | Flow 5 (at most 2 times, then a person takes over) |
| Already has the number | Flow 7 (at most once every 6 h). "ok" / "thanks" / 👍 get no reply. |
| Sends a *different* valid number | Flow 4 again. The lead is updated and the old number is kept in `alt_phones`. |
| Complaint / refund / "talk to a human" | Short "our team will reply" message. Bot goes silent and the chat is flagged **Needs a person**. |
| 4 requests for the number with no number sent | Bot goes silent and the chat is flagged for a person |
| Several messages in a burst | At most one request for the number per 45 s |
| Returning customer after 30 days | Lead is set back to **New** and Flow 7 is sent |

English messages get English replies. Romanized Nepali ("price kati ho?") gets a Nepali reply.

### Phone-number detection (`lib/phone.php`)
1. **Native first:** every request for the number includes Messenger's `user_phone_number` quick reply. It is a one-tap button pre-filled with the phone number on the customer's Facebook profile. The payload is still checked.
2. **Free text:** Devanagari digits (९८…) are converted to regular digits. Then every run of digits and separators (space, `-`, `.`, `()`) is tried, in order. Accepted forms are `98/97/96XXXXXXXX`, `+977…`, `977…` and `00977…`. The final result must match `^9[678]\d{8}$`.
3. **No false positives:** a number that is part of a longer number is ignored. Prices and quantities ("1100", "2 ota 1800", "size 42") are **not** treated as phone attempts, so they get Flow 2, not Flow 5.

## 3. Files

| File | Purpose |
|---|---|
| `webhook.php` | Meta callback URL: verification, HMAC signature check, fast 200 response, then processing |
| `lib/engine.php` | Pure decision logic for Flows 1–7 and the anti-spam limits |
| `lib/phone.php` | Nepal mobile detection and checking |
| `lib/intent.php` | Keywords (Nepali, Romanized, English) and language detection |
| `lib/messages.php` | **All reply texts.** Edit wording here only. |
| `lib/handler.php` | Database state, row locking, dedupe, lead storage, human handover |
| `lib/graph.php` | Send API, customer name lookup, pass_thread_control |
| `admin/index.php` | Lead board for the sales team, with login |
| `schema.sql` | 3 tables: `mb_conversations`, `mb_leads`, `mb_messages` |
| `tests/run_tests.php` | 110 tests (`php tests/run_tests.php --db`) |

## 4. Meta setup (one time)

1. **developers.facebook.com → Create app → type "Business"** and link it to your Business portfolio. Add the **Messenger** product.
2. **Webhooks:** Callback URL `https://YOURDOMAIN/leadbot/webhook.php`, and a Verify token equal to `verify_token` in config.php. Subscribe these fields:
   `messages`, `messaging_postbacks`, `messaging_referrals`, `message_echoes`. Add `messaging_handovers` and `standby` only if you set `pass_to_inbox`.
3. **Page token:** Business Settings → System users → Add a user (Admin) → Assign assets: the Page (full control) and the App. Generate a token with `pages_messaging`, `pages_manage_metadata`, `pages_show_list`. This token doesn't expire. Paste it into `config.php → pages`.
4. **Subscribe the Page to the app** (Messenger → Settings → "Add subscriptions" for the Page, or run
   `POST /{page-id}/subscribed_apps?subscribed_fields=messages,messaging_postbacks,messaging_referrals,message_echoes`).
5. **Test mode first:** until App Review is approved, the bot only replies to people who have a role on the app. Add 1–2 staff accounts as testers.
6. **App Review → Advanced access for `pages_messaging`.** This needs Business Verification, a privacy policy URL and data-deletion instructions on the app. Record a screencast of the flow: message → request for the number → number → confirmation.
7. **When live, switch OFF** the Business Suite **Away message** and **Contact information** automations. If you don't, customers get two replies, and the Inbox's automated echoes will pause the bot. "Leads" can stay on.

## 5. Deploy on cPanel

1. Upload the folder, e.g. to `public_html/leadbot/`. Keep the included `.htaccess` files. They block `lib/`, `tests/`, `storage/` and `config.php`.
2. cPanel → MySQL Databases: create a database and a user, then import `schema.sql` in phpMyAdmin.
3. Copy `config.sample.php` to `config.php` and fill in the app secret, verify token, Page token and database details.
   Create each sales login's hash with `php -r "echo password_hash('THEIRPASS', PASSWORD_DEFAULT);"`.
4. Make `storage/` writable (755 or 775). Errors are logged to `storage/bot.log`.
5. Open `https://YOURDOMAIN/leadbot/admin/` and log in.
6. Optional: set `'bot' => ['enabled' => false]` for a day. Leads are saved but nothing is sent, so you can watch before switching replies on.

## 6. Meta rules the bot follows
- Replies only to messages the customer sent, inside the 24-hour window, with `messaging_type: RESPONSE`. No broadcasts, no follow-up nudges, no message tags.
- Every request is signed with `X-Hub-Signature-256` and checked against the app secret. Unsigned requests get a 403.
- Meta sends webhooks again if it doesn't get a quick response. The bot replies 200 immediately and ignores repeats by message id.
- When a person needs to take over, the bot stays silent. When a staff member replies from the Business Suite Inbox, the bot pauses for 12 h for that customer. The board's **Resume bot** button turns it back on.
- Store only what you need (name, PSID, phone, messages). Delete a customer's data on request from `mb_*` using their PSID.

## 7. Customising
- Wording: `lib/messages.php`
- Keywords for "call me" and "talk to a person": `lib/intent.php`
- Limits (45 s cooldown, 4 requests, 2 invalid attempts, 6 h confirmation, 30-day reopen, 12 h human pause): `config.php → bot`
- More Pages: add `page_id => token` to `config.php → pages`. Each Page's leads are kept separately.
