<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Model;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Gift cards on a cart: add one, take one off, list what each pays. The
 * theme's checkout box (Controller\Checkout\Apply) and the GraphQL
 * mutations share it; the totals collector (Total\Quote\GiftCard) decides
 * the amounts.
 */
class CartCards
{
    public const MAX_CARDS = 5;

    public function __construct(
        private readonly Cards $cards,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @return string|null what is wrong with the code, null once the card is on the cart
     */
    public function apply(Quote $quote, string $typed): ?string
    {
        $typed = trim($typed);
        if ($typed === '') {
            return (string) __('Enter a gift card code.');
        }
        $codes = Applied::codes($quote);
        $card = $this->cards->byCode($typed);
        $error = $this->cards->problem($card, (int) $quote->getStore()->getWebsiteId());
        if ($error !== null) {
            return $error;
        }
        if (in_array($card['code'], $codes, true)) {
            return (string) __('This gift card is already on the order.');
        }
        if (count($codes) >= self::MAX_CARDS) {
            return (string) __('An order takes up to %1 gift cards.', self::MAX_CARDS);
        }
        $codes[] = (string) $card['code'];
        $this->save($quote, $codes);

        return null;
    }

    public function remove(Quote $quote, string $code): void
    {
        $code = Codes::normalize($code);
        $this->save($quote, array_values(array_diff(Applied::codes($quote), [$code])));
    }

    /**
     * @return list<array{code: string, masked: string, amount: float, base: float}> what each card on the cart pays
     */
    public function applied(Quote $quote): array
    {
        $paying = [];
        foreach (Applied::used($quote) as $use) {
            $paying[$use['code']] = ['amount' => (float) $use['amount'], 'base' => (float) $use['base']];
        }
        $cards = [];
        foreach (Applied::codes($quote) as $code) {
            $cards[] = [
                'code' => $code,
                'masked' => Codes::mask($code),
                'amount' => $paying[$code]['amount'] ?? 0.0,
                'base' => $paying[$code]['base'] ?? 0.0,
            ];
        }

        return $cards;
    }

    /**
     * A card's balance in the store's currency, for the balance page.
     *
     * @return array{masked: string, balance: float, expires_at: string|null}|string the card, or what is wrong with the code
     */
    public function balance(string $typed, int $websiteId, \Magento\Store\Api\Data\StoreInterface $store): array|string
    {
        $card = $this->cards->byCode($typed);
        $error = $this->cards->problem($card, $websiteId);
        if ($error !== null) {
            return $error;
        }

        return [
            'masked' => Codes::mask((string) $card['code']),
            'balance' => $this->priceCurrency->convertAndRound((float) $card['balance'], $store),
            'expires_at' => $card['expires_at'] ?? null,
        ];
    }

    /**
     * @param list<string> $codes
     */
    private function save(Quote $quote, array $codes): void
    {
        Applied::setCodes($quote, $codes);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->cartRepository->save($quote);
    }
}
