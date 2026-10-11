# Re: Google Ads conversions setup (reply)

Oct 11, 2026 · WyvStudio dev

Thanks, all of the Ads-side setup looks right. Below is what is live now, then answers to your seven questions.

## Live now

| Item | Status |
| --- | --- |
| Purchase label `LtsvCN-Jn5kdEOeX0PhE` | Live on app.wyvstudio.com. Fires once per confirmed payment, with value (USD) and transaction id. |
| Enhanced conversions | Live on Sign-up and Purchase. Google's tag hashes the email before sending, and only for visitors who allowed ad data. |
| URL passthrough | Live on wyvstudio.com. For "Essential only" visitors, the ad click id now rides on our links into the app. |
| Begin checkout | Wired. Fires just before the visitor leaves for the payment page. Sends nothing until you send the label. |
| Brief started | Wired. Fires once a visit, when someone saves a brief in the homepage box. Sends nothing until you send the label. |
| First video made | Not built yet. It needs a small server-side signal. We will build it when the label arrives. |

**Next on your side:**

- Make the $9 Test Pass purchase and check the `conversion` event in Tag Assistant.
- Send the three labels when the actions exist. Each one is a one-line change for us.
- Accept the customer data terms for enhanced conversions, if not already done.

## Answers

### 1. Subscription renewals

Only the first payment, made in the app, fires Purchase. Renewals happen without a visit, so nothing fires for them.

Almost every sale today is a one-time pack, so renewals are rare. If they grow, we can upload renewals to Google
Ads as offline conversions later.

### 2. Value after discounts

**Today:** the value is the **list price** in USD:

| Purchase | Value |
| --- | --- |
| Test Pass | 9 |
| Starter pack | 59 |
| Creator pack | 199 |
| Agency pack | 399 |
| Top-ups | 8, 18, 36 or 70 |

It is not the amount actually paid after coupons, and tax is not included.

**Next:** we are building a record of the amount actually paid for every purchase. Once that is in, the value
switches to the amount paid after discounts, before tax.

### 3. Non-USD payments

The value is always sent in USD, as the USD list price. Today that holds even when the payment provider shows the
buyer a local currency.

When the value switches to the amount paid (answer 2), it will be converted to USD before sending.

### 4. Refunds

They are **not** retracted automatically. Please adjust refunded purchases by hand in Google Ads (Conversions →
Uploads → Adjustments, or retract the conversion). Include the $9 test once you have refunded it.

Automatic retractions need the Google Ads API (a developer token). We can add them later if refunds become frequent.

### 5. URL passthrough

Live now on wyvstudio.com.

### 6. Source labels (Google / AppSumo / affiliate / direct)

Next in line, together with the record of amounts paid. Every account and purchase in WyvStudio will carry its
source, and Google ones will carry the click id. We will tell you when you can compare them with Ads numbers.

### 7. Landing pages per keyword group

We will build them as soon as you send the keyword groups: about half a day per page. Please include the main
keywords and the promise each ad makes, so the page can repeat it.
