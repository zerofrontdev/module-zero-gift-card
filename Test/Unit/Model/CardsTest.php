<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\GiftCard\Model\Cards;
use Zero\GiftCard\Model\Config;

/**
 * Turning a card off: one conditional UPDATE (still on; with onlyUnused,
 * still unspent), a history row only when it changed something.
 */
class CardsTest extends TestCase
{
    /** @var list<array> update / insert calls */
    private array $log = [];
    private int $changed = 1;

    public function testDeactivateRecordsTheChangeOnce(): void
    {
        self::assertTrue($this->cards()->deactivate(5, 'Order line refunded', '100000042'));

        self::assertSame([
            ['update', 'zero_giftcard', ['is_active' => 0], ['card_id = ?' => 5, 'is_active = ?' => 1]],
            ['insert', 'zero_giftcard_history', [
                'card_id' => 5, 'amount' => 0, 'balance' => 25.0, 'action' => Cards::DISABLED,
                'order_increment_id' => '100000042', 'comment' => 'Order line refunded',
            ]],
        ], $this->log);
    }

    public function testDeactivateOfAnInactiveCardDoesNothing(): void
    {
        $this->changed = 0;

        self::assertFalse($this->cards()->deactivate(5, 'Order line refunded', '100000042'));
        self::assertCount(1, $this->log, 'the UPDATE only, no history row');
    }

    public function testOnlyUnusedIsCheckedByTheUpdateItself(): void
    {
        $this->changed = 0;

        self::assertFalse($this->cards()->deactivate(5, 'Order line refunded', '100000042', true));
        self::assertSame(
            ['card_id = ?' => 5, 'is_active = ?' => 1, 'balance >= initial_amount - 0.0001'],
            $this->log[0][3],
            'a card redeemed a moment ago is not turned off'
        );
    }

    private function cards(): Cards
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('25.0000');
        $connection->method('update')->willReturnCallback(function (string $table, array $bind, $where) {
            $this->log[] = ['update', $table, $bind, $where];

            return $this->changed;
        });
        $connection->method('insert')->willReturnCallback(function (string $table, array $bind) {
            $this->log[] = ['insert', $table, $bind];

            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new Cards($resource, $this->createStub(Config::class));
    }
}
