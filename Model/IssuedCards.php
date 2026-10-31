<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;

/**
 * The cards an order line made (IssueOnInvoice), kept in line with what is
 * still paid for: a line keeps as many active cards as its invoiced qty minus
 * its refunded qty. Extra cards nobody used are turned off, the ones not
 * emailed yet first, then the newest; used cards stay on and are reported.
 * Count-based, so running it again changes nothing.
 */
class IssuedCards
{
    private const EPSILON = 0.0001;

    public function __construct(private readonly Cards $cards)
    {
    }

    /** A gift card bought on the order (the same test as IssueOnInvoice). */
    public function isGiftCardLine(Item $item): bool
    {
        $request = $item->getProductOptionByCode('info_buyRequest');

        return is_array($request) && isset($request['giftcard']) && !$item->getParentItem();
    }

    /**
     * @return array{disabled: list<array>, kept: list<array>} The cards turned off; the used ones left on
     */
    public function reconcile(Order $order, Item $item, string $comment): array
    {
        $result = ['disabled' => [], 'kept' => []];
        $incrementId = (string) $order->getIncrementId();
        $active = array_values(array_filter(
            $this->cards->forOrder($incrementId, (int) $item->getId()),
            static fn (array $card) => (bool) (int) $card['is_active']
        ));
        $keep = max(0, (int) round((float) $item->getQtyInvoiced() - (float) $item->getQtyRefunded()));
        $excess = count($active) - $keep;
        if ($excess <= 0) {
            return $result;
        }
        $unused = array_values(array_filter($active, fn (array $card) => $this->isUnused($card)));
        usort($unused, static fn (array $a, array $b) => [$a['sent_at'] !== null, -(int) $a['card_id']]
            <=> [$b['sent_at'] !== null, -(int) $b['card_id']]);
        foreach ($unused as $card) {
            if ($excess <= 0) {
                break;
            }
            if ($this->cards->deactivate((int) $card['card_id'], $comment, $incrementId, true)) {
                $result['disabled'][] = $card;
                $excess--;
            }
        }
        if ($excess > 0) {
            $result['kept'] = array_values(array_filter($active, fn (array $card) => !$this->isUnused($card)));
        }

        return $result;
    }

    private function isUnused(array $card): bool
    {
        return (float) $card['balance'] >= (float) $card['initial_amount'] - self::EPSILON;
    }
}
