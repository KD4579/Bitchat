#!/usr/bin/env python3
"""Generate the campaign templates used by /admin-cp/send_email.

Output: assets/email-campaigns/<key>.html + manifest.json (+ a local preview).
Tokens filled per recipient by Wo_McRender() (assets/includes/functions_mail_campaigns.php):
  {{FIRST_NAME}} {{NAME}} {{USERNAME}} {{EMAIL}} {{SITE_URL}} {{UNSUBSCRIBE_URL}}
  {{BONUS}} {{BONUS_UNTIL}} {{YEAR}}
Optional bonus section: <!--BONUS--> ... <!--/BONUS--> (removed when no bonus is set).

Deliverability rules baked in: text-heavy (logo is the only image), every link on
bitchat.social, unsubscribe in the footer, HTML well under Gmail's 102 KB clip limit.
"""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gen_emails import (FONT, INK, MUTED, SOFT, BRAND, THEMES, LOGO, SITE,
                        grad, button, p, small, panel, rows, features)

NAME = "Bitchat"


def campaign_layout(theme, icon, title, subtitle, body, signoff="The Bitchat Team", hero=True):
    c = THEMES[theme]
    hero_html = (
        f'<tr><td align="center" bgcolor="{c[1]}" style="{grad(c)}border-radius:20px 20px 0 0;padding:42px 32px 38px;">'
        f'<div style="display:inline-block;width:68px;height:68px;line-height:68px;border-radius:50%;background:rgba(255,255,255,.22);font-size:34px;text-align:center;">{icon}</div>'
        f'<h1 style="margin:18px 0 6px;font-family:{FONT};font-size:28px;line-height:36px;font-weight:800;color:#ffffff;">{title}</h1>'
        f'<p style="margin:0;font-family:{FONT};font-size:16px;line-height:24px;color:#ffffff;">{subtitle}</p>'
        f'</td></tr>'
    ) if hero else (
        f'<tr><td style="height:6px;line-height:6px;font-size:0;border-radius:20px 20px 0 0;{grad(c)}" bgcolor="{c[1]}">&nbsp;</td></tr>'
    )
    return (
        f'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f1ecfa" style="background-color:#f1ecfa;margin:0;padding:0;">'
        f'<tr><td align="center" style="padding:32px 12px;">'
        f'<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">'
        f'<tr><td align="center" style="padding:0 0 18px;">'
        f'<a href="{{{{SITE_URL}}}}" target="_blank" style="text-decoration:none;"><img src="{LOGO}" width="96" height="58" alt="{NAME}" style="display:block;border:0;outline:none;width:96px;height:58px;"></a>'
        f'</td></tr>'
        f'<tr><td style="background:#ffffff;border-radius:20px;box-shadow:0 8px 30px rgba(80,40,140,.10);">'
        f'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
        + hero_html +
        f'<tr><td style="padding:36px 38px 28px;">{body}</td></tr>'
        f'<tr><td style="padding:0 38px 34px;">'
        f'<div style="border-top:1px solid #eee8f7;padding-top:20px;font-family:{FONT};font-size:15px;line-height:22px;color:{INK};">'
        f'Warm regards,<br><strong style="color:{c[1]};">{signoff}</strong></div>'
        f'</td></tr>'
        f'</table></td></tr>'
        f'<tr><td align="center" style="padding:24px 20px 8px;font-family:{FONT};font-size:12px;line-height:19px;color:#8a85a0;">'
        f'<a href="{{{{SITE_URL}}}}" target="_blank" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">bitchat.social</a>'
        f'&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="mailto:support@bitchat.social" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">Help &amp; support</a>'
        f'&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{{{SITE_URL}}}}/terms/privacy-policy" target="_blank" style="color:{BRAND[1]};text-decoration:none;font-weight:600;">Privacy</a>'
        f'<br><br>You\'re receiving this because you have a Bitchat account ({{{{EMAIL}}}}).<br>'
        f'<a href="{{{{UNSUBSCRIBE_URL}}}}" target="_blank" style="color:#8a85a0;text-decoration:underline;">Unsubscribe from news emails</a>'
        f' &middot; account &amp; security emails will still reach you.'
        f'<br><br>&copy; {{{{YEAR}}}} Bitchat &middot; bitchat.social'
        f'</td></tr>'
        f'</table></td></tr></table>'
    )


def eyebrow(text, color=BRAND[2]):
    return (f'<p style="margin:0 0 10px;font-family:{FONT};font-size:12px;font-weight:800;letter-spacing:1.4px;'
            f'text-transform:uppercase;color:{color};">{text}</p>')


def bonus_box(headline, line):
    return (
        '<!--BONUS-->'
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 22px;">'
        f'<tr><td bgcolor="#fff4e8" style="background:#fff4e8;border:2px solid #ffd2a8;border-radius:16px;padding:22px 24px;text-align:center;">'
        f'<div style="font-size:30px;line-height:36px;">&#127873;</div>'
        f'<div style="font-family:{FONT};font-size:19px;line-height:27px;font-weight:800;color:{INK};margin-top:6px;">{headline}</div>'
        f'<div style="font-family:{FONT};font-size:14px;line-height:22px;color:{MUTED};margin-top:6px;">{line}</div>'
        '</td></tr></table>'
        '<!--/BONUS-->'
    )


def feature_rows(items):
    out = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:4px 0 10px;">'
    for icon, title, text, color in items:
        out += (
            f'<tr><td width="56" valign="top" style="padding:10px 14px 10px 0;">'
            f'<div style="width:46px;height:46px;line-height:46px;border-radius:14px;background:{SOFT};border-left:3px solid {color};text-align:center;font-size:22px;">{icon}</div></td>'
            f'<td valign="top" style="padding:10px 0;font-family:{FONT};">'
            f'<div style="font-size:16px;font-weight:800;color:{INK};">{title}</div>'
            f'<div style="font-size:14px;line-height:22px;color:{MUTED};margin-top:2px;">{text}</div></td></tr>'
        )
    return out + '</table>'


def address_card():
    return (
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 22px;">'
        f'<tr><td style="background:{SOFT};border-radius:16px;padding:20px 22px;text-align:center;font-family:{FONT};">'
        f'<div style="font-size:12px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:{MUTED};">Our new home</div>'
        f'<div style="margin-top:10px;font-size:15px;color:#a09bb3;text-decoration:line-through;">bitchat.live</div>'
        f'<div style="font-size:20px;line-height:20px;color:{BRAND[1]};margin:4px 0;">&#8595;</div>'
        f'<a href="{{{{SITE_URL}}}}" target="_blank" style="font-size:26px;line-height:34px;font-weight:800;color:{BRAND[2]};text-decoration:none;">bitchat.social</a>'
        f'<div style="font-size:13px;color:{MUTED};margin-top:6px;">Same account, same friends &mdash; just a new address.</div>'
        '</td></tr></table>'
    )


UTM = "utm_source=email&amp;utm_medium=campaign"
T = {}
META = {}

# 1 ── We're back
T["welcome_back"] = campaign_layout(
    "brand", "&#127881;", "We&rsquo;re back &mdash; and better than ever",
    "Bitchat has a new home at bitchat.social",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("We&rsquo;ve missed you! Bitchat was offline for a while &mdash; we used that time to rebuild, and we&rsquo;ve "
        "moved to a faster, more secure home at <strong>bitchat.social</strong>. Your account, your friends and your "
        "posts are all right where you left them.")
    + address_card()
    + bonus_box("{{BONUS}} TRDC is waiting for you",
                "Log in before <strong>{{BONUS_UNTIL}}</strong> and your welcome-back bonus is added to your wallet automatically.")
    + button("Log in to bitchat.social", "{{SITE_URL}}/welcome?" + UTM + "&amp;utm_campaign=welcome_back")
    + small('Signed up with Google? Just tap <strong>Continue with Google</strong>.<br>'
            'Forgot your password? <a href="{{SITE_URL}}/forgot-password" target="_blank" style="color:#8b3fd9;font-weight:700;">Reset it here</a>.', True)
    + '<div style="height:26px;"></div>'
    + eyebrow("What&rsquo;s waiting for you", BRAND[2])
    + feature_rows([
        ("&#128172;", "Chat, voice &amp; video calls", "Pick up your conversations right where they stopped.", BRAND[0]),
        ("&#128176;", "Earn TRDC rewards", "Get rewarded for posting, engaging and inviting friends.", BRAND[1]),
        ("&#129309;", "Groups, pages &amp; events", "Find your communities and see what they&rsquo;ve been up to.", BRAND[2]),
    ])
    + '<div style="height:8px;"></div>'
    + panel("&#128161;&nbsp; <strong>Tip:</strong> add <strong>hello@bitchat.social</strong> to your contacts so our emails always land in your inbox.", border=BRAND[2]),
)
META["welcome_back"] = dict(
    name="We're back!", icon="🎉",
    description="Relaunch on bitchat.social with the new address, an optional TRDC bonus and what's new.",
    subject="{{FIRST_NAME}}, Bitchat is back at bitchat.social",
    preheader="Same account, same friends, new home. Log in and see what's new.")

# 2 ── Reminder (bonus expiring)
T["welcome_back_reminder"] = campaign_layout(
    "warning", "&#9203;", "Your friends are waiting", "A quick reminder from Bitchat",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("A few days ago we told you Bitchat is back at <strong>bitchat.social</strong>. We haven&rsquo;t seen you yet, "
        "so here&rsquo;s a friendly nudge &mdash; your profile and conversations are ready whenever you are.")
    + bonus_box("Your {{BONUS}} TRDC bonus ends on {{BONUS_UNTIL}}",
                "Log in once before then and it&rsquo;s yours &mdash; no forms, no codes.")
    + button("Log in now", "{{SITE_URL}}/welcome?" + UTM + "&amp;utm_campaign=welcome_back_reminder", THEMES["warning"])
    + small('Signed up with Google? Tap <strong>Continue with Google</strong>. '
            'Lost your password? <a href="{{SITE_URL}}/forgot-password" target="_blank" style="color:#f97316;font-weight:700;">Reset it</a>.', True),
)
META["welcome_back_reminder"] = dict(
    name="Reminder", icon="⏳",
    description="Gentle nudge for people who haven't come back yet (bonus deadline if set).",
    subject="{{FIRST_NAME}}, your Bitchat account is waiting for you",
    preheader="Your profile and conversations are ready whenever you are.")

# 3 ── Big announcement
T["announcement"] = campaign_layout(
    "calm", "&#128227;", "Your headline goes here", "One short line that explains why it matters",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("Start with the most important news in one or two sentences. Keep it friendly and specific &mdash; "
        "what changed, and what it means for the reader.")
    + features([
        ("&#9889;", "Highlight one", "A short benefit in a few words", BRAND[0]),
        ("&#128274;", "Highlight two", "Another clear benefit", BRAND[1]),
        ("&#127775;", "Highlight three", "Something people will love", BRAND[2]),
    ])
    + '<div style="height:8px;"></div>'
    + p("Add any extra detail here. One clear call to action works better than several.")
    + button("See what&rsquo;s new", "{{SITE_URL}}/?" + UTM + "&amp;utm_campaign=announcement", THEMES["calm"]),
)
META["announcement"] = dict(
    name="Big announcement", icon="📣",
    description="Headline, three highlight cards and one button. Edit the text in the HTML box.",
    subject="{{FIRST_NAME}}, something new on Bitchat",
    preheader="A quick look at what just changed.")

# 4 ── What's new / newsletter
T["newsletter"] = campaign_layout(
    "brand", "&#10024;", "What&rsquo;s new on Bitchat", "The latest updates, in two minutes",
    p("Hi <strong>{{FIRST_NAME}}</strong>, here&rsquo;s what&rsquo;s been happening:")
    + feature_rows([
        ("&#128640;", "Update title one", "Describe the update in one or two short sentences. "
         '<a href="{{SITE_URL}}/?' + UTM + '&amp;utm_campaign=newsletter" style="color:#e8336e;font-weight:700;text-decoration:none;">Try it &rarr;</a>', BRAND[0]),
        ("&#127912;", "Update title two", "What it is and why it&rsquo;s useful. "
         '<a href="{{SITE_URL}}/?' + UTM + '&amp;utm_campaign=newsletter" style="color:#e8336e;font-weight:700;text-decoration:none;">Have a look &rarr;</a>', BRAND[1]),
        ("&#127942;", "Community spotlight", "Celebrate a great post, group or member this month.", BRAND[2]),
    ])
    + '<div style="height:6px;"></div>'
    + panel(eyebrow("Trending this week", BRAND[1])
            + f'<div style="font-family:{FONT};font-size:15px;line-height:26px;color:{INK};">'
            '&#128293;&nbsp; Trending topic one<br>&#128293;&nbsp; Trending topic two<br>&#128293;&nbsp; Trending topic three</div>',
            border=BRAND[1])
    + button("Open Bitchat", "{{SITE_URL}}/?" + UTM + "&amp;utm_campaign=newsletter"),
)
META["newsletter"] = dict(
    name="What's new / newsletter", icon="✨",
    description="Update list with icons plus a trending block. Good for a monthly roundup.",
    subject="What's new on Bitchat this month",
    preheader="New features, community highlights and what's trending.")

# 5 ── Event / promo
date_block = (
    '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:4px auto 22px;"><tr>'
    f'<td align="center" width="96" style="border-radius:16px 0 0 16px;{grad(THEMES["warning"])}padding:14px 8px;font-family:{FONT};color:#ffffff;" bgcolor="#f97316">'
    '<div style="font-size:13px;font-weight:800;letter-spacing:1px;">DEC</div><div style="font-size:36px;line-height:40px;font-weight:800;">15</div></td>'
    f'<td style="background:{SOFT};border-radius:0 16px 16px 0;padding:14px 22px;font-family:{FONT};">'
    f'<div style="font-size:17px;font-weight:800;color:{INK};">Event name</div>'
    f'<div style="font-size:14px;color:{MUTED};margin-top:2px;">Monday &middot; 18:00 UTC &middot; Online on Bitchat</div></td>'
    '</tr></table>'
)
T["event_promo"] = campaign_layout(
    "warning", "&#127881;", "You&rsquo;re invited", "Join us for something special",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("Tell people what&rsquo;s happening and why they won&rsquo;t want to miss it.")
    + date_block
    + p("Add the key details: what to expect, who&rsquo;s hosting, and any perk for joining.")
    + button("Save my spot", "{{SITE_URL}}/events?" + UTM + "&amp;utm_campaign=event", THEMES["warning"]),
)
META["event_promo"] = dict(
    name="Event / promo", icon="📅",
    description="Date block, short pitch and one button. Change the date and details in the HTML.",
    subject="{{FIRST_NAME}}, you're invited",
    preheader="Join us on Bitchat. Here are the details.")

# 6 ── Simple branded letter
T["simple_letter"] = campaign_layout(
    "brand", "", "", "",
    p("Hi <strong>{{FIRST_NAME}}</strong>,")
    + p("Write your message here. A short, personal note from the team often gets the best response &mdash; "
        "keep paragraphs short and get to the point quickly.")
    + p("Add a second paragraph if you need it.")
    + button("Visit Bitchat", "{{SITE_URL}}/?" + UTM + "&amp;utm_campaign=letter"),
    hero=False,
)
META["simple_letter"] = dict(
    name="Simple letter", icon="✉️",
    description="Logo, a thin brand stripe and plain paragraphs. Feels personal; best inbox placement.",
    subject="A note from the Bitchat team",
    preheader="")

SAMPLE = {"FIRST_NAME": "Alex", "NAME": "Alex Rivera", "USERNAME": "alex_r", "EMAIL": "alex@example.com",
          "SITE_URL": SITE, "UNSUBSCRIBE_URL": SITE + "/unsubscribe.php?u=1&amp;c=1&amp;t=x", "BONUS": "50",
          "BONUS_UNTIL": "14 December 2026", "YEAR": "2026"}

if __name__ == "__main__":
    root = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
    out = os.path.join(root, "assets", "email-campaigns")
    os.makedirs(out, exist_ok=True)
    manifest = []
    for k, html in T.items():
        assert len(html) < 60000, k
        open(os.path.join(out, k + ".html"), "w").write(html)
        manifest.append(dict(key=k, **META[k]))
    json.dump(manifest, open(os.path.join(out, "manifest.json"), "w"), ensure_ascii=False, indent=1)
    prev = sys.argv[1] if len(sys.argv) > 1 else None
    if prev:
        os.makedirs(prev, exist_ok=True)
        for k, html in T.items():
            s = html.replace(LOGO, "../email-logo.png").replace("<!--BONUS-->", "").replace("<!--/BONUS-->", "")
            for a, b in SAMPLE.items():
                s = s.replace("{{" + a + "}}", b)
            open(os.path.join(prev, f"c_{k}.html"), "w").write(
                '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">' + s + '</body></html>')
    for k, html in T.items():
        print(f"{k:24s} {len(html):6d}")
