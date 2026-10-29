<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\GiftCard\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Zero\GiftCard\Model\CartCards;
use Zero\GiftCard\Model\Codes;

/**
 * The checkout's gift card box (HTMX POST; form key checked by core's CSRF
 * validation): adds the card in `giftcard_code` (if it can pay), or takes
 * off the one in `remove`; collects the totals; answers with the box and
 * the totals (out of band), as the discount code does.
 */
class Apply implements HttpPostActionInterface
{
    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly RequestInterface $request,
        private readonly CartCards $cartCards
    ) {
    }

    /**
     * @return Layout
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $error = null;
        $typed = trim((string) $this->request->getParam('giftcard_code'));
        if ($quote->getId()) {
            $remove = (string) $this->request->getParam('remove');
            if ($remove !== '') {
                $this->cartCards->remove($quote, $remove);
                $typed = '';
            } else {
                $error = $this->cartCards->apply($quote, $typed);
                if ($error === null) {
                    $typed = '';
                }
            }
        }
        $resultLayout = $this->resultLayoutFactory->create();
        $resultLayout->addDefaultHandle();
        $layout = $resultLayout->getLayout();
        $box = $layout->getBlock('zero.giftcard.checkout');
        if ($box) {
            $box->setData('error', $error)->setData('typed', $error !== null ? Codes::normalize($typed) : '');
        }
        $layout->addOutputElement('zero.giftcard.checkout');
        $layout->addOutputElement('zero.checkout.ordersummarytotals');

        return $resultLayout;
    }
}
