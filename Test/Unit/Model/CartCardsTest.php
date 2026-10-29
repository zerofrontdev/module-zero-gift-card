<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Test\Unit\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Zero\GiftCard\Model\Applied;
use Zero\GiftCard\Model\Cards;
use Zero\GiftCard\Model\CartCards;
use Zero\GiftCard\Model\Codes;

#[AllowMockObjectsWithoutExpectations]
class CartCardsTest extends TestCase
{
    private Cards $cards;
    private CartCards $cartCards;
    private Quote $quote;
    private int $saved = 0;

    protected function setUp(): void
    {
        $this->cards = $this->createStub(Cards::class);
        $this->cards->method('byCode')->willReturnCallback(fn (string $typed) => match (Codes::normalize($typed)) {
            'GOOD-CARD-GOOD-CARD' => ['card_id' => 1, 'code' => 'GOOD-CARD-GOOD-CARD', 'balance' => 25.0, 'website_id' => 2, 'is_active' => 1, 'expires_at' => null],
            default => null,
        });
        $this->cards->method('problem')->willReturnCallback(fn (?array $card) => $card === null ? 'There is no gift card with this code.' : null);
        $repository = $this->createStub(CartRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function () {
            $this->saved++;
        });
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $this->quote = $this->createPartialMock(Quote::class, ['getStore', 'collectTotals']);
        $this->quote->method('getStore')->willReturn($store);
        $this->quote->method('collectTotals')->willReturnSelf();
        $this->cartCards = new CartCards($this->cards, $repository, $this->createStub(PriceCurrencyInterface::class));
    }

    public function testAppliesAKnownCardOnce(): void
    {
        $this->assertNull($this->cartCards->apply($this->quote, 'good card good card'));
        $this->assertSame(['GOOD-CARD-GOOD-CARD'], Applied::codes($this->quote));
        $this->assertSame('This gift card is already on the order.', $this->cartCards->apply($this->quote, 'GOOD-CARD-GOOD-CARD'));
        $this->assertSame(1, $this->saved);
    }

    public function testRefusesEmptyAndUnknownCodesWithoutSaving(): void
    {
        $this->assertSame('Enter a gift card code.', $this->cartCards->apply($this->quote, '  '));
        $this->assertSame('There is no gift card with this code.', $this->cartCards->apply($this->quote, 'NOPE'));
        $this->assertSame(0, $this->saved);
    }

    public function testStopsAtTheLimit(): void
    {
        Applied::setCodes($this->quote, ['A', 'B', 'C', 'D', 'E']);

        $this->assertSame('An order takes up to 5 gift cards.', $this->cartCards->apply($this->quote, 'GOOD-CARD-GOOD-CARD'));
    }

    public function testRemovesAndListsWhatEachCardPays(): void
    {
        Applied::setCodes($this->quote, ['GOOD-CARD-GOOD-CARD', 'OTHER-CARD']);
        $this->quote->setData('zero_giftcard_used', json_encode([['code' => 'GOOD-CARD-GOOD-CARD', 'card_id' => 1, 'base' => 25.0, 'amount' => 25.0]]));

        $applied = $this->cartCards->applied($this->quote);
        $this->assertSame([['code' => 'GOOD-CARD-GOOD-CARD', 'masked' => '••••-CARD', 'amount' => 25.0, 'base' => 25.0], ['code' => 'OTHER-CARD', 'masked' => '••••-CARD', 'amount' => 0.0, 'base' => 0.0]], $applied);

        $this->cartCards->remove($this->quote, 'good-card-good-card');
        $this->assertSame(['OTHER-CARD'], Applied::codes($this->quote));
        $this->assertSame(1, $this->saved);
    }
}
