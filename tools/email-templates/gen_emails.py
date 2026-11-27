#!/usr/bin/env python3
"""Generate branded Bitchat transactional email templates (Wo_HTML_Emails).

Each template only uses the placeholders its PHP loader actually replaces.
Output: emails/<name>.html (raw DB value) + preview.html (all, with sample data).
"""
import json, os, html as H

SITE = "https://bitchat.social"
LOGO = SITE + "/themes/wondertag/img/email-logo.png"
FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif"
INK, MUTED, SOFT = "#1f1b2e", "#6b6780", "#f6f3fc"

# Brand gradient taken from the logo (orange -> pink -> purple)
BRAND = ("#ff7a2f", "#e8336e", "#8b3fd9")
THEMES = {
    "brand":   BRAND,
    "success": ("#10b981", "#0ea5a4", "#3b82f6"),
    "warning": ("#f59e0b", "#f97316", "#e8336e"),
    "danger":  ("#f43f5e", "#e8336e", "#8b3fd9"),
    "calm":    ("#8b3fd9", "#6d5dfc", "#3b82f6"),
}


def grad(c):
    return f"background-color:{c[1]};background-image:linear-gradient(135deg,{c[0]} 0%,{c[1]} 55%,{c[2]} 100%);"


def button(label, href, c=BRAND):
    return (
        f'<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:28px auto 8px;">'
        f'<tr><td align="center" bgcolor="{c[1]}" style="border-radius:999px;{grad(c)}">'
        f'<a href="{href}" target="_blank" style="display:inline-block;padding:15px 38px;font-family:{FONT};font-size:16px;'
        f'font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;letter-spacing:.2px;">{label}</a>'
        f'</td></tr></table>'
    )


def p(text, extra=""):
    return f'<p style="margin:0 0 16px;font-family:{FONT};font-size:16px;line-height:26px;color:{INK};{extra}">{text}</p>'


def small(text, center=False):
    a = "text-align:center;" if center else ""
    return f'<p style="margin:0 0 10px;font-family:{FONT};font-size:13px;line-height:20px;color:{MUTED};{a}">{text}</p>'


def panel(inner, border=None):
    b = f"border-left:4px solid {border};" if border else ""
    return (
        f'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 20px;">'
        f'<tr><td style="background:{SOFT};border-radius:14px;padding:20px 22px;{b}font-family:{FONT};font-size:15px;line-height:24px;color:{INK};">'
        f'{inner}</td></tr></table>'
    )


def rows(items):
    out = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
    for k, v in items:
        out += (
            f'<tr><td style="padding:7px 0;font-family:{FONT};font-size:14px;color:{MUTED};width:38%;vertical-align:top;">{k}</td>'
            f'<td style="padding:7px 0;font-family:{FONT};font-size:14px;color:{INK};font-weight:600;vertical-align:top;">{v}</td></tr>'
        )
    return out + "</table>"


def features(items):
    out = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 8px;"><tr>'
    for icon, title, text, color in items:
        out += (
            f'<td width="33%" valign="top" style="padding:6px;text-align:center;">'
            f'<div style="background:{SOFT};border-radius:14px;padding:18px 10px;border-top:3px solid {color};">'
            f'<div style="font-size:26px;line-height:32px;">{icon}</div>'
            f'<div style="font-family:{FONT};font-size:14px;font-weight:700;color:{INK};margin-top:6px;">{title}</div>'
            f'<div style="font-family:{FONT};font-size:12px;line-height:18px;color:{MUTED};margin-top:4px;">{text}</div>'
            f'</div></td>'
        )
    return out + "</tr></table>"


def layout(theme, icon, title, subtitle, body, site_name="{{SITE_NAME}}", footer_note=None):
    c = THEMES[theme]
    note = footer_note or f"You're receiving this email because you have an account on {site_name}."
    return (
        f'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f1ecfa" style="background-color:#f1ecfa;margin:0;padding:0;">'
        f'<tr><td align="center" style="padding:32px 12px;">'
        f'<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">'
        # logo
        f'<tr><td align="center" style="padding:0 0 18px;">'
        f'<a href="{SITE}" target="_blank" style="text-decoration:none;"><img src="{LOGO}" width="96" height="58" alt="{site_name}" style="display:block;border:0;outline:none;width:96px;height:58px;"></a>'
        f'</td></tr>'
        # card
        f'<tr><td style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(80,40,140,.10);">'
        f'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
        # hero
        f'<tr><td align="center" bgcolor="{c[1]}" style="{grad(c)}border-radius:20px 20px 0 0;padding:40px 32px 36px;">'
        f'<div style="display:inline-block;width:68px;height:68px;line-height:68px;border-radius:50%;background:rgba(255,255,255,.22);font-size:34px;text-align:center;">{icon}</div>'
        f'<h1 style="margin:18px 0 6px;font-family:{FONT};font-size:26px;line-height:34px;font-weight:800;color:#ffffff;">{title}</h1>'
        f'<p style="margin:0;font-family:{FONT};font-size:15px;line-height:22px;color:#ffffff;opacity:.92;">{subtitle}</p>'
        f'</td></tr>'
        # body
        f'<tr><td style="padding:36px 38px 30px;">{body}</td></tr>'
        # sign-off
        f'<tr><td style="padding:0 38px 34px;">'
        f'<div style="border-top:1px solid #eee8f7;padding-top:20px;font-family:{FONT};font-size:15px;line-height:22px;color:{INK};">'
        f'Cheers,<br><strong style="color:{c[1]};">The {site_name} Team</strong></div>'
        f'</td></tr>'
        f'</table></td></tr>'
        # footer
        f'<tr><td align="center" style="padding:24px 20px 8px;font-family:{FONT};font-size:12px;line-height:19px;color:#8a85a0;">'
        f'<a href="{SITE}" target="_blank" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">Visit {site_name}</a>'
        f'&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="mailto:support@bitchat.social" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">Contact support</a>'
        f'&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{SITE}/terms/privacy-policy" target="_blank" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">Privacy</a>'
        f'<br><br>{note}<br>&copy; {site_name} &middot; bitchat.social'
        f'</td></tr>'
        f'</table></td></tr></table>'
    )


T = {}

# ---- activate: USERNAME, SITE_URL, EMAIL, CODE, SITE_NAME
T["activate"] = layout(
    "brand", "&#127881;", "Welcome to {{SITE_NAME}}!", "Just one quick step to get you started",
    p("Hi <strong>{{USERNAME}}</strong>,")
    + p("We're thrilled to have you join our community! Please confirm your email address so we know it's really you and can keep your account secure.")
    + button("Confirm my email", "{{SITE_URL}}/index.php?link1=activate&amp;email={{EMAIL}}&amp;code={{CODE}}")
    + small('This confirms <strong>{{EMAIL}}</strong> for your account.', True)
    + f'<div style="height:22px;"></div>'
    + f'<p style="margin:0 0 6px;font-family:{FONT};font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:{BRAND[2]};text-align:center;">What\'s waiting for you</p>'
    + features([
        ("&#128172;", "Chat live", "Real-time messages, voice &amp; video calls", BRAND[0]),
        ("&#128248;", "Share moments", "Posts, photos, videos &amp; stories", BRAND[1]),
        ("&#129309;", "Find your people", "Groups, pages &amp; events", BRAND[2]),
    ])
    + f'<div style="height:12px;"></div>'
    + small("Didn't sign up for {{SITE_NAME}}? You can safely ignore this email and no account will be activated."),
)

# ---- invite: USERNAME, SITE_URL, NAME, URL, SITE_NAME, BACKGOUND_COLOR
T["invite"] = layout(
    "brand", "&#128140;", "You've been invited!", "Your friend wants you on {{SITE_NAME}}",
    p("Hi there,")
    + p('<a href="{{URL}}" target="_blank" style="color:#e8336e;font-weight:700;text-decoration:none;">{{NAME}}</a> has invited you to join them on <strong>{{SITE_NAME}}</strong> &mdash; the place to chat, share and stay close to the people who matter.')
    + panel(
        '<div style="font-size:15px;line-height:26px;">'
        "&#10024;&nbsp; Chat in real time with friends<br>"
        "&#128247;&nbsp; Share photos, videos and stories<br>"
        "&#127775;&nbsp; Discover groups, pages and events</div>", border="#e8336e")
    + button("Accept invitation", "{{SITE_URL}}/register?ref={{USERNAME}}")
    + small('Joining is free and takes less than a minute.', True),
)

# ---- login_with: FIRST_NAME, SITE_NAME, USERNAME, EMAIL   (no password is sent)
T["login_with"] = layout(
    "brand", "&#128075;", "You're in, {{FIRST_NAME}}!", "Your {{SITE_NAME}} account is ready",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("Thanks for joining <strong>{{SITE_NAME}}</strong> with your social account. Here are your account details:")
    + panel(rows([("Username", "@{{USERNAME}}"), ("Email", "{{EMAIL}}")]), border="#8b3fd9")
    + p("Sign in anytime using the same social account. Prefer a password as well? Use <strong>Forgot password</strong> on the login page to set one.")
    + button("Start exploring", SITE)
    + small('Tip: add a profile photo and follow a few people to light up your feed.', True),
)

# ---- notification: SITE_NAME, NOTIFY_URL, NOTIFY_AVATAR, NOTIFY_NAME, TEXT_TYPE, TEXT, URL
T["notification"] = layout(
    "calm", "&#128276;", "New activity for you", "Something's happening on {{SITE_NAME}}",
    '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 18px;">'
    '<tr><td width="64" valign="middle" style="padding-right:14px;">'
    '<a href="{{NOTIFY_URL}}" target="_blank"><img src="{{NOTIFY_AVATAR}}" width="60" height="60" alt="" '
    'style="display:block;width:60px;height:60px;border-radius:50%;border:3px solid #e8336e;object-fit:cover;"></a></td>'
    f'<td valign="middle" style="font-family:{FONT};">'
    '<a href="{{NOTIFY_URL}}" target="_blank" style="font-size:17px;font-weight:800;color:#1f1b2e;text-decoration:none;">{{NOTIFY_NAME}}</a>'
    '<div style="font-size:15px;color:#6b6780;margin-top:3px;">{{TEXT_TYPE}}</div></td></tr></table>'
    + f'<div style="font-family:{FONT};font-size:15px;line-height:24px;color:{INK};font-style:italic;margin:0 0 6px;">{{{{TEXT}}}}</div>'
    + button("View on {{SITE_NAME}}", "{{URL}}", THEMES["calm"]),
    footer_note=f'Getting too many emails? <a href="{SITE}/setting/email" target="_blank" style="color:#8a85a0;text-decoration:underline;">Manage your email notifications</a>.',
)

# ---- payment_declined: {{name}}, {{amount}}, {{site_name}}  (lowercase, replaced in xhr/admin_setting.php)
T["payment_declined"] = layout(
    "warning", "&#9888;&#65039;", "Payment request update", "We couldn't process this one",
    p("Hi <strong>{{name}}</strong>,")
    + p("Unfortunately, your payment request could not be approved this time.")
    + panel(rows([("Amount", '<span style="font-size:20px;color:#f97316;">${{amount}}</span>'), ("Status", '<span style="color:#e8336e;">Declined</span>')]), border="#f97316")
    + p("This can happen if your payout details are incomplete or the request didn't meet our withdrawal requirements. Please review your details and try again, or reach out and we'll be happy to help.")
    + button("Contact support", "mailto:support@bitchat.social", THEMES["warning"]),
    site_name="{{site_name}}",
)

# ---- payment_approved: {{name}}, {{amount}}, {{site_name}}
T["payment_approved"] = layout(
    "success", "&#128184;", "Your payment is on its way!", "Good news &mdash; your request was approved",
    p("Hi <strong>{{name}}</strong>,")
    + p("Great news! Your payment request has been approved and the money has been sent.")
    + panel(
        f'<div style="text-align:center;"><div style="font-size:13px;color:{MUTED};text-transform:uppercase;letter-spacing:1px;">Amount sent</div>'
        '<div style="font-size:38px;line-height:48px;font-weight:800;color:#10b981;margin-top:4px;">${{amount}}</div>'
        '<div style="display:inline-block;margin-top:8px;padding:4px 14px;border-radius:999px;background:#d1fae5;color:#047857;font-size:13px;font-weight:700;">&#10003; Approved</div></div>')
    + p("Depending on your payment method, it may take a little while to appear in your account. Thanks for being part of the community!")
    + button("Go to {{site_name}}", SITE, THEMES["success"]),
    site_name="{{site_name}}",
)

# ---- recover: USERNAME, SITE_NAME, LINK
T["recover"] = layout(
    "calm", "&#128273;", "Reset your password", "Let's get you back into your account",
    p("Hi <strong>{{USERNAME}}</strong>,")
    + p("We received a request to reset the password for your {{SITE_NAME}} account. Click the button below to choose a new one.")
    + button("Reset my password", "{{LINK}}", THEMES["calm"])
    + small('Button not working? Copy this link into your browser:<br>'
            '<a href="{{LINK}}" style="color:#6d5dfc;word-break:break-all;">{{LINK}}</a>', True)
    + f'<div style="height:14px;"></div>'
    + panel("&#128274;&nbsp; <strong>Didn't ask for this?</strong> You can ignore this email &mdash; your password won't change unless you use the link above.", border="#6d5dfc"),
)

# ---- unusual_login: USERNAME, SITE_NAME, CODE, DATE, EMAIL, COUNTRY_CODE, IP_ADDRESS, CITY
T["unusual_login"] = layout(
    "danger", "&#128737;&#65039;", "Is this you signing in?", "We noticed a sign-in from a new device or location",
    p("Hi <strong>{{USERNAME}}</strong>,")
    + p("Your sign-in attempt looks a little different from usual. To keep your account safe, please confirm it's you by entering this code:")
    + '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:22px auto 26px;"><tr>'
    f'<td align="center" style="background:{SOFT};border:2px dashed #e8336e;border-radius:16px;padding:18px 34px;'
    f'font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:34px;font-weight:800;letter-spacing:8px;color:#1f1b2e;">{{{{CODE}}}}</td></tr></table>'
    + f'<p style="margin:0 0 8px;font-family:{FONT};font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8b3fd9;">Sign-in details</p>'
    + panel(rows([("Date", "{{DATE}}"), ("Account", "{{EMAIL}}"), ("Location", "{{CITY}}, {{COUNTRY_CODE}}"), ("IP address", "{{IP_ADDRESS}}")]), border="#8b3fd9")
    + panel(f'&#9888;&#65039;&nbsp; <strong>Wasn\'t you?</strong> Don\'t share this code with anyone. '
            f'<a href="{SITE}/forgot-password" target="_blank" style="color:#e8336e;font-weight:700;">Reset your password</a> right away to secure your account.', border="#f43f5e"),
    footer_note="This is a security notification about your account, so it's sent even if you've turned off other emails.",
)

# ---- account_deleted: {{name}}, SITE_URL, SITE_NAME
T["account_deleted"] = layout(
    "calm", "&#128075;", "Sorry to see you go", "Your account has been deleted",
    p("Hi <strong>{{name}}</strong>,")
    + p("This is a confirmation that your <strong>{{SITE_NAME}}</strong> account has been deleted and your data has been erased from our platform.")
    + p("Thank you for the time you spent with us. If you ever want to come back, you're always welcome &mdash; it only takes a minute to create a new account.")
    + button("Create a new account", "{{SITE_URL}}/register", THEMES["calm"])
    + f'<div style="height:10px;"></div>'
    + panel("&#128274;&nbsp; <strong>Didn't request this?</strong> Please contact us right away at "
            '<a href="mailto:security@bitchat.social" style="color:#6d5dfc;font-weight:700;">security@bitchat.social</a>.', border="#6d5dfc"),
    footer_note="This is a one-time confirmation. You won't receive further emails from us.",
)

SAMPLE = {
    "SITE_NAME": "BITCHAT", "site_name": "BITCHAT", "SITE_URL": SITE, "USERNAME": "alex_r", "name": "Alex Rivera",
    "NAME": "Jordan Lee", "FIRST_NAME": "Alex", "EMAIL": "alex@example.com", "CODE": "482913",
    "URL": SITE + "/jordan", "LINK": SITE + "/reset-password/abc123", "amount": "150.00",
    "NOTIFY_URL": SITE + "/jordan", "NOTIFY_AVATAR": SITE + "/upload/photos/d-avatar.jpg", "NOTIFY_NAME": "Jordan Lee",
    "TEXT_TYPE": "commented on your post", "TEXT": "“This is amazing, congrats! \U0001F525”",
    "DATE": "27 Nov 2026, 09:41", "COUNTRY_CODE": "AE", "IP_ADDRESS": "203.0.113.24", "CITY": "Dubai",
    "BACKGOUND_COLOR": "#c64d53",
}

if __name__ == "__main__":
    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "emails")
    os.makedirs(out, exist_ok=True)
    for k, v in T.items():
        open(os.path.join(out, k + ".html"), "w").write(v)
    json.dump(T, open(os.path.join(out, "all.json"), "w"))
    blocks = []
    for k, v in T.items():
        s = v
        for a, b in SAMPLE.items():
            s = s.replace("{{" + a + "}}", b)
        blocks.append(f'<h2 style="font-family:sans-serif;margin:40px 0 10px;text-align:center;">{k} ({len(v):,} chars)</h2>{s}')
    open(os.path.join(out, "preview.html"), "w").write(
        '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bitchat emails preview</title></head>'
        '<body style="margin:0;background:#ddd;">' + "".join(blocks) + "</body></html>")
    for k, v in T.items():
        print(f"{k:18s} {len(v):6d}")
