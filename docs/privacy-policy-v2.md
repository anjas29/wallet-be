# Privacy Policy

_Last updated: 14 October 2026_

SpendWise ("SpendWise", "the App", "we", "us", or "our") is a personal finance
tracking application operated by an individual developer based in Indonesia.
This Privacy Policy explains what information SpendWise collects, how it is
used, and the choices you have. It applies to the SpendWise mobile/web
application and its backend API.

SpendWise is a **manual expense and income tracker**. Its tracking features
do not move, hold, or transmit real money, do not connect to your bank
account or card, and do not perform identity (KYC) verification. Any
"wallet" or "balance" shown in the App is a number you or the App calculated
from entries you typed in or receipts you uploaded — it is not a financial
account.

The only real payment in SpendWise is an optional paid **subscription**,
which is processed by **Stripe** (see Section 5).

## 1. Who We Are

SpendWise is operated by an individual developer. For any privacy question,
request, or complaint, you can reach us at:

- Email: `anjas.adi.nugroho29@gmail.com`

Because SpendWise is run by an individual rather than a registered company,
this Privacy Policy is written to be honest about that scale — we do not
have a dedicated legal or compliance department, but we take the handling of
your data seriously and will respond to requests personally.

## 2. Information We Collect

### 2.1 Information you provide directly

- **Account information**: name, email address, and password (stored as a
  salted hash, never in plain text).
- **Financial entries you enter manually**: transaction descriptions,
  amounts, dates, categories, and any notes you add.
- **Budgets and liabilities**: budget limits you set and any debts/liabilities
  and repayments you choose to record, again entered manually by you.
- **Receipt images**: if you use the receipt-scanning feature, the photo you
  upload and the data extracted from it (merchant, amount, date, line items).
- **AI chat messages**: any text or images you send to the in-app AI
  assistant, and the assistant's replies, so your conversation history can be
  shown back to you.
- **Subscription and billing information**: if you subscribe to a paid plan,
  you enter your payment details directly on Stripe's hosted checkout page,
  not in SpendWise. We receive and store only limited billing information
  from Stripe: your Stripe customer ID, the plan you are subscribed to, the
  subscription status and trial/renewal dates, and the card type and last
  four digits of your card (for example "Visa ending in 4242").

### 2.2 Information collected automatically

- **Session and device data**: IP address, user agent, and session
  identifiers, used only to keep you signed in and to secure your account.
- **Basic usage logs**: standard server logs (timestamps, endpoint called,
  response status) kept for a limited time for debugging and abuse
  prevention.

We do **not** collect or store government ID documents, full bank account
or card numbers, card security codes (CVC), or biometric data. Full payment
card details are collected and stored only by Stripe.

## 3. How We Use Your Information

We use the information above only to:

- Provide the core functionality of the App: recording, categorizing, and
  displaying your income, expenses, budgets, and liabilities.
- Operate the AI assistant and receipt-scanning features you choose to use.
- Process your subscription, determine which features your plan includes,
  and manage trials, renewals, and cancellations.
- Authenticate you and keep your account secure.
- Diagnose bugs and keep the service running.
- Respond to support requests you send us.

We do not use your financial entries for advertising, and we do not sell
your data.

## 4. AI Features and Third-Party Processing

SpendWise's AI chat assistant and receipt-scanning feature are built on
**Google's Gemini API**. When you use these features:

- The text you type, and any receipt image or chat image you attach, is sent
  to Google's Gemini API to generate a response or extract receipt data.
- Google processes this data as our sub-processor under its own API terms
  and privacy commitments; we do not control Google's infrastructure and
  encourage you to review
  [Google's Gemini API terms and privacy information](https://ai.google.dev/gemini-api/terms)
  if you want details on how Google itself handles API data.
- Images are resized/compressed on our server before being sent, and only a
  limited number of recent images per conversation are re-sent on each
  follow-up message, to control cost — this is a technical limit, not a
  privacy control, so avoid uploading receipts or images containing
  information you don't want processed by Google's API.

If you do not use the AI chat or receipt-scanning features, no data is sent
to Google.

## 5. Payments and Subscriptions (Stripe)

Paid subscriptions to SpendWise are processed by **Stripe**
([stripe.com](https://stripe.com)), a third-party payment processor. When you
subscribe:

- You are redirected to a Stripe-hosted checkout page. Your card number,
  expiry date, and security code are entered directly into Stripe and are
  **never sent to or stored on SpendWise's servers**.
- To create your Stripe customer record, we share your name and email
  address with Stripe.
- Stripe sends us back the limited billing information listed in Section 2.1
  (customer ID, plan, subscription status and dates, card type and last four
  digits), and notifies our server of subscription events such as payment
  success, renewal, or cancellation.
- Stripe may collect additional information (such as billing address,
  country, and device or fraud-prevention signals) as needed to process the
  payment, prevent fraud, and meet its legal obligations. Stripe handles this
  data under its own
  [Privacy Policy](https://stripe.com/privacy).

If you never subscribe to a paid plan, none of your data is sent to Stripe.

We do not use any other third-party analytics, advertising, or tracking
service. Aside from Google's Gemini API (Section 4) and Stripe (this
section), your data is not shared with any other third party.

## 6. Data Sharing and Disclosure

We do not sell, rent, or trade your personal information. We only disclose
data:

- To Google, solely for the AI/receipt-scanning features described in
  Section 4, and only for messages/images you actively submit.
- To Stripe, solely to process subscription payments as described in
  Section 5, and only if you subscribe to a paid plan.
- If required by law, court order, or governmental request.
- If necessary to protect the security or integrity of the App (e.g.
  investigating abuse).

## 7. Data Security

We apply reasonable technical safeguards appropriate to a service of this
size, including:

- Passwords stored using one-way hashing, never in plain text.
- HTTPS/TLS encryption for data in transit.
- Payment card details handled entirely by Stripe, so they never touch our
  servers.
- Access to the production database restricted to the developer.

As with any online service, no method of transmission or storage is 100%
secure, and we cannot guarantee absolute security.

## 8. Data Retention

We keep your account and financial entries for as long as your account is
active. AI conversation history is kept until you delete it or close your
account. If you delete your account, we will delete your personal data and
financial entries from our production database within 30 days, except where
we are required to retain limited records (e.g. server logs kept for
security purposes) for a short additional period.

If you had a paid subscription, deleting your account cancels it
immediately. Limited billing records (subscription history and the billing
information described in Section 2.1) are kept as a record of the
transactions for accounting, tax, and dispute purposes. Stripe also retains
payment records according to its own legal obligations and
[Privacy Policy](https://stripe.com/privacy).

## 9. Your Rights

You may, at any time:

- Access, correct, or export the data you have entered in the App.
- Delete individual entries from within the App, or request deletion of your
  entire account and its data at
  [wallet.birchlabs.tech/account-delete-request](https://wallet.birchlabs.tech/account-delete-request) —
  no need to email us.
- Cancel your subscription at any time.
- Ask us what personal data we hold about you.

If you are located in Indonesia, these rights are provided consistent with
Law No. 27 of 2022 on Personal Data Protection (UU PDP). If you are located
elsewhere, we will still make reasonable efforts to honor requests of this
kind regardless of which jurisdiction's law formally applies.

## 10. Children's Privacy

SpendWise is not directed at children, and we do not knowingly collect
personal information from children. If you believe a child has provided us
personal information, contact us and we will delete it.

## 11. International Data Transfers

Our servers may be located in a different country from where you use the
App, and the Gemini API (Section 4) and Stripe (Section 5) are operated by
third parties that may process data outside your country. By using
SpendWise, you understand your data may be transferred to and processed in
other countries, which may have different data protection laws than your
own.

## 12. Changes to This Privacy Policy

We may update this Privacy Policy from time to time as the App's features
change. If we make material changes, we will update the "Last updated" date
above and, where practical, notify you in the App. Continued use of
SpendWise after a change means you accept the updated policy.

## 13. Contact Us

Questions, requests, or complaints about this Privacy Policy or your data
can be sent to: `anjas.adi.nugroho29@gmail.com`.
