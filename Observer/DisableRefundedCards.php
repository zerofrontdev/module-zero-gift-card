<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Zero\GiftCard\Model\IssuedCards;

/**
 * A refunded (sales_order_creditmemo_refund) or cancelled (order_cancel_after)
 * gift card line turns off the cards it made that nobody used; used ones stay
 * on, and the order says which (admin-only comments). On a refund only the
 * memo's lines with a qty count (an adjustment alone changes no card); runs
 * inside the memo's transaction, so a failed memo undoes it.
 */
class DisableRefundedCards implements ObserverInterface
{
    public function __construct(private readonly IssuedCards $issued)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $creditmemo = $event->getData('creditmemo');
        if ($creditmemo) {
            $order = $creditmemo->getOrder();
            $items = [];
            foreach ($creditmemo->getAllItems() as $memoItem) {
                if ((float) $memoItem->getQty() > 0 && $memoItem->getOrderItem()) {
                    $items[] = $memoItem->getOrderItem();
                }
            }
            $comment = (string) __('Order line refunded');
            $cancelled = false;
        } else {
            $order = $event->getData('order');
            $items = $order ? $order->getAllItems() : [];
            $comment = (string) __('Order cancelled');
            $cancelled = true;
        }
        if (!$order instanceof Order) {
            return;
        }
        foreach ($items as $item) {
            if (!$this->issued->isGiftCardLine($item)) {
                continue;
            }
            $this->comment($order, $this->issued->reconcile($order, $item, $comment), $cancelled);
        }
    }

    private function comment(Order $order, array $result, bool $cancelled): void
    {
        foreach ($result['disabled'] as $card) {
            $phrase = $cancelled
                ? __('Gift card %1 turned off: its order was cancelled.', $card['code'])
                : __('Gift card %1 turned off: its order line was refunded.', $card['code']);
            $order->addCommentToStatusHistory(
                $phrase->render(),
                false,
                false
            );
        }
        $currency = $result['kept'] ? $order->getBaseCurrency() : null;
        foreach ($result['kept'] as $card) {
            $order->addCommentToStatusHistory(
                __(
                    'Gift card %1 was already used (%2 of %3 left), so it stays on. Turn it off under Marketing > Gift Cards if the refund should take it back.',
                    $card['code'],
                    $currency->formatTxt((float) $card['balance']),
                    $currency->formatTxt((float) $card['initial_amount'])
                )->render(),
                false,
                false
            );
        }
    }
}
