<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;
use Zero\GiftCard\Model\Cards;
use Zero\GiftCard\Model\IssuedCards;

/**
 * A gift card line keeps max(0, invoiced - refunded) active cards: the extra
 * unused ones are turned off (unsent first, then newest), used ones stay on
 * and are reported, cards already off count, and a second run changes nothing.
 */
class IssuedCardsTest extends TestCase
{
    /** @var array<int, array> card id => row, as forOrder() returns them */
    private array $rows = [];
    /** @var list<array> deactivate() calls */
    private array $deactivated = [];
    /** @var list<int> cards whose conditional UPDATE finds them spent meanwhile */
    private array $spentMeanwhile = [];

    public function testRefundedQtyTurnsOffUnusedCardsUnsentFirst(): void
    {
        $this->card(1, 25, 25, '2026-10-01 10:00:00');
        $this->card(2, 25, 25, '2026-10-01 10:00:00');
        $this->card(3, 25, 25, null);

        $result = $this->issued()->reconcile($this->order(), $this->line(3, 2), 'Order line refunded');

        self::assertSame([
            [3, 'Order line refunded', '100000042', true],
            [2, 'Order line refunded', '100000042', true],
        ], $this->deactivated, 'the unsent card, then the newest');
        self::assertSame([3, 2], $this->ids($result['disabled']));
        self::assertSame([], $result['kept']);
    }

    public function testUnsentCardsGoNewestFirst(): void
    {
        $this->card(4, 25, 25, null);
        $this->card(5, 25, 25, null);
        $this->card(6, 25, 25, '2026-10-01 10:00:00');

        $this->issued()->reconcile($this->order(), $this->line(3, 1), 'Order line refunded');

        self::assertSame([5], array_column($this->deactivated, 0));
    }

    public function testPartUsedCardsStayOnAndAreReported(): void
    {
        $this->card(1, 10, 25, '2026-10-01 10:00:00');
        $this->card(2, 25, 25, '2026-10-01 10:00:00');

        $result = $this->issued()->reconcile($this->order(), $this->line(2, 2), 'Order line refunded');

        self::assertSame([2], array_column($this->deactivated, 0), 'the used card is never turned off');
        self::assertSame([2], $this->ids($result['disabled']));
        self::assertSame([1], $this->ids($result['kept']));
    }

    public function testUsedCardsAreNotReportedWhenNoExcessIsLeft(): void
    {
        $this->card(1, 10, 25, '2026-10-01 10:00:00');
        $this->card(2, 25, 25, '2026-10-01 10:00:00');

        $result = $this->issued()->reconcile($this->order(), $this->line(2, 1), 'Order line refunded');

        self::assertSame([2], $this->ids($result['disabled']));
        self::assertSame([], $result['kept']);
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->card(1, 25, 25, '2026-10-01 10:00:00');
        $this->card(2, 25, 25, null);
        $issued = $this->issued();
        $issued->reconcile($this->order(), $this->line(2, 1), 'Order line refunded');
        self::assertCount(1, $this->deactivated);

        $again = $issued->reconcile($this->order(), $this->line(2, 1), 'Order line refunded');

        self::assertCount(1, $this->deactivated, 'no second card turned off');
        self::assertSame(['disabled' => [], 'kept' => []], $again);
    }

    public function testCardsTheAdminTurnedOffCount(): void
    {
        $this->card(1, 25, 25, '2026-10-01 10:00:00', false);
        $this->card(2, 25, 25, '2026-10-01 10:00:00');
        $this->card(3, 25, 25, '2026-10-01 10:00:00');

        $result = $this->issued()->reconcile($this->order(), $this->line(3, 1), 'Order line refunded');

        self::assertSame([], $this->deactivated, 'two left active for two still paid');
        self::assertSame(['disabled' => [], 'kept' => []], $result);
    }

    public function testACardSpentMeanwhileIsSkippedAndTheNextUnusedOneTaken(): void
    {
        $this->card(1, 25, 25, '2026-10-01 10:00:00');
        $this->card(2, 25, 25, '2026-10-01 10:00:00');
        $this->spentMeanwhile = [2];

        $result = $this->issued()->reconcile($this->order(), $this->line(2, 1), 'Order line refunded');

        self::assertSame([2, 1], array_column($this->deactivated, 0));
        self::assertSame([1], $this->ids($result['disabled']));
    }

    public function testAnOverRefundedLineTurnsOffEveryUnusedCardAndReportsTheUsedOnes(): void
    {
        // More refunded than invoiced: nothing is kept for the line. The unused card goes off,
        // the used one cannot be turned off and is reported. (The max(0) clamp on keep is not
        // observable here: a negative keep turns off the same cards and reports the same ones.)
        $this->card(1, 25, 25, null);
        $this->card(2, 10, 25, '2026-10-01 10:00:00');

        $result = $this->issued()->reconcile($this->order(), $this->line(1, 3), 'Order cancelled');

        self::assertSame([[1, 'Order cancelled', '100000042', true]], $this->deactivated);
        self::assertSame([1], $this->ids($result['disabled']));
        self::assertSame([2], $this->ids($result['kept']));
    }

    public function testIsGiftCardLine(): void
    {
        $issued = $this->issued();
        $parent = $this->createStub(Item::class);

        self::assertTrue($issued->isGiftCardLine($this->line(1, 0, ['giftcard' => ['amount' => '25']])));
        self::assertFalse($issued->isGiftCardLine($this->line(1, 0, ['giftcard' => ['amount' => '25']], $parent)));
        self::assertFalse($issued->isGiftCardLine($this->line(1, 0, ['qty' => 1])));
        self::assertFalse($issued->isGiftCardLine($this->line(1, 0, null)));
    }

    /** @return list<int> */
    private function ids(array $cards): array
    {
        return array_map('intval', array_column($cards, 'card_id'));
    }

    private function issued(): IssuedCards
    {
        $cards = $this->createStub(Cards::class);
        $cards->method('forOrder')->willReturnCallback(fn (string $incrementId, ?int $itemId) => $incrementId === '100000042' && $itemId === 81
            ? array_values($this->rows)
            : []);
        $cards->method('deactivate')->willReturnCallback(function (int $cardId, string $comment, ?string $incrementId, bool $onlyUnused) {
            $this->deactivated[] = [$cardId, $comment, $incrementId, $onlyUnused];
            if (in_array($cardId, $this->spentMeanwhile, true) || !(int) $this->rows[$cardId]['is_active']) {
                return false;
            }
            $this->rows[$cardId]['is_active'] = '0';

            return true;
        });

        return new IssuedCards($cards);
    }

    private function card(int $id, float $balance, float $initial, ?string $sentAt, bool $active = true): void
    {
        $this->rows[$id] = [
            'card_id' => (string) $id,
            'code' => sprintf('CARD-%04d', $id),
            'balance' => sprintf('%.4F', $balance),
            'initial_amount' => sprintf('%.4F', $initial),
            'sent_at' => $sentAt,
            'is_active' => $active ? '1' : '0',
        ];
    }

    private function order(): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn('100000042');

        return $order;
    }

    private function line(float $invoiced, float $refunded, ?array $buyRequest = ['giftcard' => ['amount' => '25']], ?Item $parent = null): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getId')->willReturn(81);
        $item->method('getQtyInvoiced')->willReturn($invoiced);
        $item->method('getQtyRefunded')->willReturn($refunded);
        $item->method('getProductOptionByCode')->willReturnCallback(static fn ($code) => $code === 'info_buyRequest' ? $buyRequest : null);
        $item->method('getParentItem')->willReturn($parent);

        return $item;
    }
}
