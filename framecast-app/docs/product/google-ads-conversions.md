# Google Ads conversions: setup guide

For whoever runs WyvStudio's Google Ads account (AW-18506583015). Last updated 2026-10-11.

## How tracking works here

- **The Google tag is already installed.** It is on wyvstudio.com and app.wyvstudio.com. It loads on every page with
  Consent Mode: no cookies until the visitor presses **Allow** in the cookie banner. Visitors who don't allow it are
  still counted, without cookies, and Google models those conversions.
- **Our code sends every conversion.** It sends each one at the exact moment it really happens: after an account is
  created, or after a payment is confirmed.
- **So in Google Ads you only create the action and send us its label.** You do **not** need:
  - Google Tag Manager;
  - the Tag Assistant "Set up purchase conversions" wizard;
  - "Extract data from your page" rules;
  - Page load or URL rules.

> **Never set a conversion's event to "Page load: https://app.wyvstudio.com/"** (or any URL rule). The tag runs on
> every app page, so Google would count a conversion every time anyone opens the app.

## Which domain to use

**Use wyvstudio.com in Google Ads.**

| Where | Use |
|---|---|
| Ads' final URLs | `https://wyvstudio.com`, or a matching landing page on wyvstudio.com once we have one per keyword group |
| "Your website" when Google asks | `https://wyvstudio.com` |
| Conversions | These happen on `app.wyvstudio.com` (sign-up, payment). Nothing to set: it is the same site and the same tag. |

How an ad click stays linked to the sale:

- **Visitors who allow cookies.** Google keeps the click id in a cookie on `.wyvstudio.com`. Both wyvstudio.com and
  app.wyvstudio.com can read it, so a sign-up or purchase in the app is matched to the ad click on wyvstudio.com
  automatically.
- **Visitors who choose "Essential only".**
  - **Today:** there is no cookie, so the click id is lost between wyvstudio.com and the app.
  - **Planned:** we will switch on Google's **URL passthrough** on wyvstudio.com. It adds the ad's click id to our links
    into the app without cookies, so these conversions still link to the right ad.
  - **On your side:** nothing to do.
- **Don't send ads straight to app.wyvstudio.com.** It is a sign-in page with no message or prices.

## What a label is

Every conversion action has an event snippet like this:

```html
gtag('event', 'conversion', { 'send_to': 'AW-18506583015/z_DsCOG_lJkdEOeX0PhE' });
```

The **label** is the part after the slash: `z_DsCOG_lJkdEOeX0PhE`. Send us that string.

The "Conversion type ID" (a number such as 7836811085) is **not** the label.

---

## 1. Sign-up (done)

| Setting | Value |
|---|---|
| Label | `z_DsCOG_lJkdEOeX0PhE`, live since 2026-10-11 |
| When it fires | Right after an account is created. A failed or duplicate sign-up does not count. |
| Value sent | $1 USD |

**Check its settings in Google Ads** (Goals → Conversions → Sign-up → Edit settings):

| Setting | Should be |
|---|---|
| Event | The code / event-snippet option, **not** "Page load" |
| Category / optimisation | Sign-up |
| Value | Use the same value: $1 (our code sends $1 anyway) |
| Count | One |
| Click-through window | 30 days |
| Primary or secondary | **Secondary** if you want the ads to bid for purchases; **Primary** only while there are too few purchases to learn from |

## 2. Purchase (needs a new label)

**Step by step:**

1. Go to **Goals → Conversions → Summary → + New conversion action → Website**.
2. When it asks for a website URL, choose **"Add a conversion action manually"**. Don't use the URL or page-load
   option, and don't use the purchase wizard.
3. Fill in:

   | Setting | Value |
   |---|---|
   | Goal and action optimisation | **Purchase** |
   | Conversion name | Purchase |
   | Value | **Use different values for each conversion**, default $1, currency USD |
   | Count | **Every** |
   | Click-through window | 30 days |
   | Engaged-view window | 3 days |
   | View-through window | 1 day |
   | Attribution | Data-driven |

4. Press **Done**, then **Save and continue**.
5. On **Set up the tag**, choose **"Use Google tag"** and then **"Install the tag yourself"**. Ignore the global tag
   part, because it is already installed.
6. Copy the **event snippet** and send us the label (the part after `AW-18506583015/`).
7. If the existing "Purchase" action has **Event: Page load: https://app.wyvstudio.com/**, open it, set it to
   **Secondary** (or remove it), so that it never steers bidding.

**What our code sends:**

- **When:** after the payment provider confirms the payment, on the app's payment confirmation step.
- **Value:** the real price, in USD:

  | Purchase | Value |
  |---|---|
  | Test Pass | 9 |
  | Starter pack | 59 |
  | Creator pack | 199 |
  | Agency pack | 399 |
  | Top-up 500 / 1,200 / 2,500 / 5,000 credits | 8 / 18 / 36 / 70 |
  | Monthly Starter / Creator / Pro / Agency | 29 / 59 / 99 / 199 |

- **Transaction ID:** the checkout id, so a refresh or a second visit never counts the same purchase twice.

## 3. Any other event (how to add one)

1. **Decide the moment**, in plain words: "someone starts checkout", "someone finishes their first video".
2. **Create the action** in Google Ads exactly like Purchase:
   - **New conversion action → Website → Add a conversion action manually.**
   - Pick the category that fits.
   - Set the value: "Use the same value" for a step, "different values" only when money changes hands.
   - Set the count: **One** for a step a person does once, **Every** for repeat events.
   - Choose **Use Google tag → Install the tag yourself** and copy the label.
3. **Make it Secondary** unless you want Google to bid for it. Usually only Purchase (and early on, Sign-up) is
   Primary.
4. **Send us the moment and the label.** We add one line in the app at that moment, test it and push it. It is live
   in a few minutes.

**Events we can add on request:**

| Event | Fires when | Suggested count | Why |
|---|---|---|---|
| Begin checkout | Someone presses a plan or Test Pass button and goes to payment | Every | Shows ad clicks that nearly bought |
| First video made | An account's first finished video | One | The real sign of a good customer |
| Brief started | Someone types a brief on wyvstudio.com and goes to sign up | One | Shows intent before sign-up |

## 4. Check that it works

1. **Tag present.**
   - Install the **Google Tag Assistant** Chrome extension and open wyvstudio.com and app.wyvstudio.com.
   - The tag AW-18506583015 should show as found on both.
2. **Sign-up.** Make a test account with a fresh email. Tag Assistant shows a `conversion` event with the Sign-up
   label.
3. **Purchase.** Once the label is in, a real $9 Test Pass purchase shows a `conversion` event with value 9 and USD.
   It can be refunded within 14 days.
4. **In Google Ads.** The action's status moves from "Unverified" or "No recent conversions" to **Recording**. This
   can take a few hours.
5. **Diagnostics.** Goals → Conversions → the action → **Diagnostics** shows tag health and any errors.

## 5. Things to know

- **Consent:** someone who presses "Essential only" is still counted, without cookies, and Google models those
  conversions. Expect reported conversions to be close to, but not exactly, the real number.
- **Where else people buy:** AppSumo buyers and affiliate-referred buyers buy elsewhere and do not come from your
  ads. They will not appear as Google conversions.
  - We plan to label every account and purchase by source (Google, AppSumo, affiliate, direct) inside WyvStudio, so
    the numbers can be compared.
- **Enhanced conversions** (sending a hashed email for better matching) is optional. Ask if you want it, and we can
  add it to Sign-up and Purchase.
- **The name:** WyvStudio is said "weave studio".
