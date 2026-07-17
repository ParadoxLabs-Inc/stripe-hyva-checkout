<?php declare(strict_types=1);
/**
 * Copyright © 2023-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\StripeHyvaCheckout\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use ParadoxLabs\Stripe\Model\ConfigProvider;

class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * ConfirmationToken flow additional_data contract: exactly one of {token, card_id} populated
     * per submit, plus the save-card flag. token carries a ctoken_ (fresh tokenization) or a
     * pi_/seti_ (3DS resubmit after handleNextAction).
     */
    private const ALLOWED_KEYS
        = [
            'token' => null,
            'card_id' => null,
            'save' => null,
        ];

    /**
     * Assign the client payment data to the quote payment, then place the order.
     *
     * importData() (not addData()) so the payment_method_assign_data observer chain runs — that is
     * what copies token into additional_information (classifying ctoken_ vs pi_/seti_) and
     * resolves card_id into tokenbase_id.
     *
     * On a Stripe requires_action (3DS) result, Method::confirmFromToken() throws
     * StripeAuthenticationRequiredException; its generic message surfaces through the default
     * handleException() rethrow, and the client recovers the challenge via the payment
     * component's getNextAction() on the order:place error event.
     *
     * @throws CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function placeOrder(Quote $quote): int
    {
        $paymentData = (array)$this->getData()->getPayment();

        // Only pass through known allowed values, to prevent parameter injection
        $knownPaymentData = array_intersect_key(
            $paymentData,
            self::ALLOWED_KEYS,
        );

        $quote->getPayment()->importData([
            PaymentInterface::KEY_METHOD => ConfigProvider::CODE,
            PaymentInterface::KEY_ADDITIONAL_DATA => $knownPaymentData,
        ]);

        return parent::placeOrder($quote);
    }
}
