# Zero_GiftCard

Gift cards: sold as a product, emailed once paid, spent at checkout like
money.

- **The product**: a virtual product with **Gift Card Amounts** set ("25, 50,
  100", base currency) is a gift card; `bin/magento zero:giftcard:create-product`
  makes one (no tax class, every website). Its page asks for the amount, who
  it is for (name, email), from whom and a message; the cart line costs the
  amount and shows the recipient and message (cart, order, emails).
- **Paid**: each paid invoice makes a card per gift card bought on it (worth
  what it cost), and once the invoice is saved emails it to the recipient,
  or to the buyer when no recipient email was given (template "Gift Card",
  sender per store). Buying a card earns no reward points.
- **Spending**: at checkout, "Have a gift card?" in the order summary (up to
  5 cards). Cards pay the grand total, shipping and tax included (not a
  discount: tax is not lowered), in the order added, each up to its balance;
  the summary shows "Gift card". Nothing left to pay: Zero Checkout offers
  only "No Payment Information Required" (also for a 100% discount). The
  balance is taken when the order is placed (locked: a card can't pay twice);
  given back if placing fails or the order is cancelled.
- **Invoices and refunds**: invoices are paid by the cards first; a credit
  memo gives back to the cards first (the last used first), the rest to the
  payment. A credit memo that refunds a gift card line turns off as many of
  the cards it made as were refunded, among those nobody used (not emailed
  yet first, then the newest), each noted in the card's history ("Order line
  refunded") and on the order. A card already used stays on, and the order
  says so: turn it off by hand if the refund should take it back. A card
  turned off by hand counts; a refund without qty (an adjustment) changes no
  card.
- **Balance**: `/giftcard/balance?code=...` (linked from the email).
- **Marketing > Gift Cards** (admin): every card (search by code, recipient
  or order), one card's history; make a card by hand (and email it), turn a
  card off or on, email it again, change its balance with a note.

Codes: 16 characters in four groups, no look-alike characters; typed codes
are read in any case, with or without dashes. Cards are per website, in its
base currency; expiry optional (Cards Expire After, Days). Settings: Stores >
Configuration > Zero > Gift Cards.

Tables: `zero_giftcard`, `zero_giftcard_history`; columns on `quote`,
`sales_order`, `sales_invoice`, `sales_creditmemo`.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-gift-card
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Tests

`Test/Unit/Model/CodesTest.php`; `dev/tests/zero-e2e/tests/giftcard.spec.ts`
(`ZERO_GIFTCARD_PRODUCT`), `giftcard-admin.spec.ts` (a card made by hand pays
a whole order).

## Disabling or removing

Its data lives in its own tables (`zero_giftcard`, `zero_giftcard_history`). With the module's code still in
place, `bin/magento module:disable Zero_GiftCard` followed by `setup:upgrade`
**drops those tables** (Magento's declarative schema), and enabling the module
again brings them back empty. To keep the data, switch the feature off in
Stores > Configuration where it has a setting, or back up first:
`bin/magento setup:upgrade --safe-mode=1` dumps the data before anything is
dropped (`--data-restore=1` puts it back). Deleting the module's code instead
leaves the tables in place.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
