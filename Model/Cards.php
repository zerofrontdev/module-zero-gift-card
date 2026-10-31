<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Gift cards (zero_giftcard) and their history. A balance only changes
 * through change(), which locks the card's row and records the change.
 */
class Cards
{
    public const CREATED = 'created';
    public const USED = 'used';
    public const RETURNED = 'returned';
    public const REFUNDED = 'refunded';
    public const ADMIN = 'admin';
    public const DISABLED = 'disabled';

    private const TABLE = 'zero_giftcard';
    private const HISTORY = 'zero_giftcard_history';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @param array $data sender_name, recipient_name, recipient_email, message, store_id, order_increment_id, order_item_id
     * @return array The card
     */
    public function create(float $amount, int $websiteId, array $data = [], ?string $comment = null): array
    {
        $connection = $this->resource->getConnection();
        $days = $this->config->expireDays($websiteId);
        $row = array_intersect_key($data, array_flip([
            'sender_name', 'recipient_name', 'recipient_email', 'message', 'store_id', 'order_increment_id', 'order_item_id',
        ])) + [
            'website_id' => $websiteId,
            'initial_amount' => round($amount, 4),
            'balance' => round($amount, 4),
            'expires_at' => $days ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null,
        ];
        for ($try = 0; $try < 5; $try++) {
            $row['code'] = Codes::generate();
            try {
                $connection->insert($this->resource->getTableName(self::TABLE), $row);
                break;
            } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
                if ($try === 4) {
                    throw $e;
                }
            }
        }
        $id = (int) $connection->lastInsertId($this->resource->getTableName(self::TABLE));
        $connection->insert($this->resource->getTableName(self::HISTORY), [
            'card_id' => $id,
            'amount' => round($amount, 4),
            'balance' => round($amount, 4),
            'action' => self::CREATED,
            'order_increment_id' => $data['order_increment_id'] ?? null,
            'comment' => $comment,
        ]);

        return $this->get($id);
    }

    public function get(int $cardId): ?array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('card_id = ?', $cardId)
        ) ?: null;
    }

    public function byCode(string $typed): ?array
    {
        $code = Codes::normalize($typed);
        if ($code === '') {
            return null;
        }
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('code = ?', $code)
        ) ?: null;
    }

    /**
     * Why a card can't pay on a website, or null when it can.
     */
    public function problem(?array $card, int $websiteId): ?string
    {
        return match (true) {
            $card === null => (string) __('There is no gift card with this code.'),
            !(int) $card['is_active'] => (string) __('This gift card is not active.'),
            $card['expires_at'] !== null && $card['expires_at'] <= gmdate('Y-m-d H:i:s') => (string) __('This gift card has expired.'),
            (int) $card['website_id'] !== $websiteId => (string) __('This gift card is for another store.'),
            (float) $card['balance'] <= 0.0001 => (string) __('This gift card has nothing left on it.'),
            default => null,
        };
    }

    /**
     * Adds to the balance (positive) or takes from it (negative: all of it,
     * or nothing when the balance is short).
     *
     * @throws LocalizedException When the balance is short
     */
    public function change(int $cardId, float $amount, string $action, ?string $orderIncrementId = null, ?string $comment = null): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            $balance = (float) $connection->fetchOne(
                $connection->select()->from($table, ['balance'])->where('card_id = ?', $cardId)->forUpdate(true)
            );
            $after = round($balance + $amount, 4);
            if ($after < -0.0001) {
                throw new LocalizedException(__('A gift card on this order has less left on it than the order uses.'));
            }
            $connection->update($table, ['balance' => max(0.0, $after)], ['card_id = ?' => $cardId]);
            $connection->insert($this->resource->getTableName(self::HISTORY), [
                'card_id' => $cardId,
                'amount' => round($amount, 4),
                'balance' => max(0.0, $after),
                'action' => $action,
                'order_increment_id' => $orderIncrementId,
                'comment' => $comment !== null ? mb_substr($comment, 0, 255) : null,
            ]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function used(int $cardId, string $orderIncrementId): float
    {
        $connection = $this->resource->getConnection();

        return -(float) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::HISTORY), ['SUM(amount)'])
                ->where('card_id = ?', $cardId)
                ->where('order_increment_id = ?', $orderIncrementId)
                ->where('action IN (?)', [self::USED, self::RETURNED, self::REFUNDED])
        );
    }

    public function setActive(int $cardId, bool $active): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['is_active' => (int) $active],
            ['card_id = ?' => $cardId]
        );
    }

    /**
     * Turns an active card off and records it in its history (amount 0);
     * false when it was already off (nothing written). With $onlyUnused, only
     * while nothing was spent from it (checked by the UPDATE itself, so a card
     * redeemed a moment ago stays on).
     */
    public function deactivate(int $cardId, string $comment, ?string $orderIncrementId = null, bool $onlyUnused = false): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $where = ['card_id = ?' => $cardId, 'is_active = ?' => 1];
        if ($onlyUnused) {
            $where[] = 'balance >= initial_amount - 0.0001';
        }
        $changed = $connection->update($table, ['is_active' => 0], $where);
        if ($changed < 1) {
            return false;
        }
        $balance = (float) $connection->fetchOne($connection->select()->from($table, ['balance'])->where('card_id = ?', $cardId));
        $connection->insert($this->resource->getTableName(self::HISTORY), [
            'card_id' => $cardId,
            'amount' => 0,
            'balance' => round($balance, 4),
            'action' => self::DISABLED,
            'order_increment_id' => $orderIncrementId,
            'comment' => mb_substr($comment, 0, 255),
        ]);

        return true;
    }

    public function markSent(int $cardId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['sent_at' => gmdate('Y-m-d H:i:s')],
            ['card_id = ?' => $cardId]
        );
    }

    /**
     * @return array<int, array>
     */
    public function forOrder(string $orderIncrementId, ?int $orderItemId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('order_increment_id = ?', $orderIncrementId)
            ->order('card_id');
        if ($orderItemId !== null) {
            $select->where('order_item_id = ?', $orderItemId);
        }

        return $connection->fetchAll($select);
    }

    /**
     * @return array<int, array>
     */
    public function history(int $cardId): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::HISTORY))
                ->where('card_id = ?', $cardId)
                ->order('history_id DESC')
        );
    }

    /**
     * Newest first; a search matches the code, the recipient or the order.
     *
     * @return array<int, array>
     */
    public function all(string $search = '', int $limit = 200): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->order('card_id DESC')
            ->limit($limit);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $select->where('code LIKE ? OR recipient_email LIKE ? OR recipient_name LIKE ? OR order_increment_id LIKE ?', $like);
            $code = Codes::normalize($search);
            if ($code !== $search) {
                $select->orWhere('code = ?', $code);
            }
        }

        return $connection->fetchAll($select);
    }
}
