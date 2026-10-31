# Changelog: zerofront/module-zero-gift-card

## 1.3.6

- Refunding a gift card's order line, or cancelling its order, turns off the cards it made that nobody has used; used cards stay on and the order says which (admin-only comments).
- A card is turned off only while nothing was spent from it, checked in the same database update.
- Array-valued codes in the apply form no longer cause a 500.

Earlier releases (1.3.5 and before): see the Git tags.
