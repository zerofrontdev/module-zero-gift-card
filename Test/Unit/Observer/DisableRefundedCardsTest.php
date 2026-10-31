<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Test\Unit\Observer;

use Magento\Directory\Model\Currency;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as MemoItem;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;
use Zero\GiftCard\Model\IssuedCards;
use Zero\GiftCard\Observer\DisableRefundedCards;

/**
 * A refund reconciles only the memo's gift card lines with a qty; a cancel
 * reconciles every gift card line of the order; the order says which cards
 * were turned off (refund or cancel wording) and which used ones stay on.
 */
class DisableRefundedCardsTest extends TestCase
{
    /** @var list<array{0: Item, 1: string}> reconcile() calls: the line and the comment */
    private array $reconciled = [];
    /** @var list<array> addCommentToStatusHistory() calls */
    private array $comments = [];
    private array $result = ['disabled' => [], 'kept' => []];
    private Item $giftLine;
    private Item $otherGiftLine;
    private Item $plainLine;

    protected function setUp(): void
    {
        $this->giftLine = $this->createStub(Item::class);
        $this->otherGiftLine = $this->createStub(Item::class);
        $this->plainLine = $this->createStub(Item::class);
    }

    public function testOnlyGiftCardLinesWithRefundedQtyAreReconciled(): void
    {
        $order = $this->order();
        $memo = $this->createStub(Creditmemo::class);
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getAllItems')->willReturn([
            $this->memoItem(1, $this->giftLine),
            $this->memoItem(0, $this->otherGiftLine),
            $this->memoItem(2, $this->plainLine),
            $this->memoItem(1, null),
        ]);

        $this->observer()->execute(new Observer(['event' => new Event(['creditmemo' => $memo])]));

        self::assertCount(1, $this->reconciled);
        self::assertSame($this->giftLine, $this->reconciled[0][0]);
        self::assertSame('Order line refunded', $this->reconciled[0][1]);
    }

    public function testCommentsAreAddedToTheOrder(): void
    {
        $this->result = [
            'disabled' => [['code' => 'AAAA-BBBB-CCCC-DDDD']],
            'kept' => [['code' => 'EEEE-FFFF-GGGG-HHHH', 'balance' => '10.0000', 'initial_amount' => '25.0000']],
        ];
        $order = $this->order();
        $memo = $this->createStub(Creditmemo::class);
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getAllItems')->willReturn([$this->memoItem(1, $this->giftLine)]);

        $this->observer()->execute(new Observer(['event' => new Event(['creditmemo' => $memo])]));

        self::assertSame([
            ['Gift card AAAA-BBBB-CCCC-DDDD turned off: its order line was refunded.', false, false],
            ['Gift card EEEE-FFFF-GGGG-HHHH was already used ($10.00 of $25.00 left), so it stays on. Turn it off under Marketing > Gift Cards if the refund should take it back.', false, false],
        ], $this->comments);
    }

    public function testCancelReconcilesEveryGiftCardLine(): void
    {
        $this->result = ['disabled' => [['code' => 'AAAA-BBBB-CCCC-DDDD']], 'kept' => []];
        $order = $this->order([$this->giftLine, $this->plainLine, $this->otherGiftLine]);

        $this->observer()->execute(new Observer(['event' => new Event(['order' => $order])]));

        self::assertSame([$this->giftLine, $this->otherGiftLine], array_column($this->reconciled, 0));
        self::assertSame(['Order cancelled', 'Order cancelled'], array_column($this->reconciled, 1));
        self::assertSame('Gift card AAAA-BBBB-CCCC-DDDD turned off: its order was cancelled.', $this->comments[0][0]);
    }

    public function testNoOrderChangesNothing(): void
    {
        $this->observer()->execute(new Observer(['event' => new Event([])]));

        self::assertSame([], $this->reconciled);
    }

    private function observer(): DisableRefundedCards
    {
        $issued = $this->createStub(IssuedCards::class);
        $issued->method('isGiftCardLine')->willReturnCallback(fn (Item $item) => $item === $this->giftLine || $item === $this->otherGiftLine);
        $issued->method('reconcile')->willReturnCallback(function (Order $order, Item $item, string $comment) {
            $this->reconciled[] = [$item, $comment];

            return $this->result;
        });

        return new DisableRefundedCards($issued);
    }

    private function order(array $items = []): Order
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('formatTxt')->willReturnCallback(static fn ($amount) => sprintf('$%.2f', $amount));
        $order = $this->createStub(Order::class);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->method('addCommentToStatusHistory')->willReturnCallback(function (...$args) use ($order) {
            $this->comments[] = $args;

            return $order;
        });

        return $order;
    }

    private function memoItem(float $qty, ?Item $orderItem): MemoItem
    {
        $item = $this->createStub(MemoItem::class);
        $item->method('getQty')->willReturn($qty);
        $item->method('getOrderItem')->willReturn($orderItem);

        return $item;
    }
}
