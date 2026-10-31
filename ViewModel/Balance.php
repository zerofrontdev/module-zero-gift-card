<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\GiftCard\Model\Cards;
use Zero\GiftCard\Model\Codes;

/**
 * The balance page: the code asked about, and what is left on it.
 */
class Balance implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Cards $cards,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function code(): string
    {
        $codeParam = $this->request->getParam('code');
        return Codes::normalize(mb_substr(is_scalar($codeParam) ? (string) $codeParam : '', 0, 40));
    }

    /**
     * @return array{balance: string, initial: string, expires: ?string, problem: ?string}|null
     */
    public function result(): ?array
    {
        $code = $this->code();
        if ($code === '') {
            return null;
        }
        $store = $this->storeManager->getStore();
        $card = $this->cards->byCode($code);
        $problem = $this->cards->problem($card, (int) $store->getWebsiteId());
        if ($card === null) {
            return ['balance' => '', 'initial' => '', 'expires' => null, 'problem' => $problem];
        }
        $currency = $store->getBaseCurrency();

        return [
            'balance' => $currency->formatPrecision((float) $card['balance'], 2, [], false),
            'initial' => $currency->formatPrecision((float) $card['initial_amount'], 2, [], false),
            'expires' => $card['expires_at'] ? substr((string) $card['expires_at'], 0, 10) : null,
            'problem' => (float) $card['balance'] <= 0.0001 ? null : $problem,
        ];
    }
}
