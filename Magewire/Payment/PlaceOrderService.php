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

use Exception;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use ParadoxLabs\Stripe\Exception\StripeAuthenticationRequiredException;
use ParadoxLabs\Stripe\Model\ConfigProvider;
use Throwable;

class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * Place-order failure buffered by handleException() for evaluateCompletion()/canRedirect().
     *
     * @var Exception|null
     */
    protected ?Exception $placeOrderException = null;


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
     * StripeAuthenticationRequiredException from inside parent::placeOrder(). StashNextActionPlugin
     * (which wraps this method, so it observes the throw before the processor does) persists the
     * challenge data onto the quote payment; the processor then queues the order:place error event
     * and hands the exception to handleException() below. The client recovers the challenge via the
     * payment component's getNextAction() on that event.
     *
     * The addData() that follows importData() is load-bearing, not redundant: Hyva's
     * AbstractPlaceOrderService passes the quote payment into CartManagement::placeOrder(),
     * and QuoteManagement::placeOrderRun re-runs importData($payment->getData()). The Stripe
     * assign observer unconditionally writes additional_information.token from the incoming
     * data — which carries no top-level token after the first assign — so without the
     * re-staged keys that second import would overwrite the just-stored ConfirmationToken
     * with '' and every new-card order would fail at confirm.
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

        $payment = $quote->getPayment();
        $payment->importData([
            PaymentInterface::KEY_METHOD => ConfigProvider::CODE,
            PaymentInterface::KEY_ADDITIONAL_DATA => $knownPaymentData,
        ]);

        // Re-stage the allowed keys as raw payment data so the re-import re-asserts them
        // through the observer chain instead of clearing them (see docblock).
        $payment->addData($knownPaymentData);

        return parent::placeOrder($quote);
    }

    /**
     * Accept a place-order failure instead of rethrowing.
     *
     * The default AbstractPlaceOrderService::handleException() rethrows. That rethrow aborts the
     * Magewire request before the queued order:place:{method}:error browser events reach the
     * response effects, so the client-side 3DS recovery (getNextAction -> handleNextAction ->
     * re-place) and the spent-token scrub in scripts.phtml never run, and in production the
     * customer only sees a generic "page refresh" dialog.
     *
     * Buffering the exception lets the request complete normally, which delivers those events;
     * evaluateCompletion() then surfaces the (sanitized) outcome and canRedirect() suppresses the
     * unconditional success-page redirect the processor would otherwise push for the failed
     * attempt.
     *
     * StashNextActionPlugin has already persisted any 3DS challenge data by this point: it wraps
     * placeOrder() and runs to completion (stash saved, then rethrow) before the processor calls
     * this method, so the non-rethrow behavior here does not affect the stash.
     */
    public function handleException(Exception $exception, Component $component, Quote $quote): void
    {
        $this->placeOrderException = $exception;
    }

    /**
     * Report the place-order outcome: default success behavior when an order was placed, otherwise
     * a failure message.
     *
     * A requires_action (3DS) failure is not a decline the customer should be alarmed by — the
     * scripts.phtml order:place:{method}:error listener immediately runs the challenge and
     * re-places the order — so it is softened to a neutral, non-leaking note (never the raw
     * challenge exception) while still returning a failure result so no success/redirect fires and
     * the event-driven recovery can proceed. A genuine failure after the challenge is reported
     * inline by runNextAction().
     *
     * For every other failure, a LocalizedException message is safe to show verbatim (the decline
     * reason); anything else is masked, mirroring the processor's own event-detail policy. Never
     * returns the parent's unconditional success for a failed attempt.
     */
    public function evaluateCompletion(
        EvaluationResultFactory $resultFactory,
        int|null $orderId = null,
    ): EvaluationResultInterface {
        if ($this->placeOrderException === null) {
            return parent::evaluateCompletion($resultFactory, $orderId);
        }

        $errorMessage = $resultFactory->createErrorMessage();

        if ($this->findAuthException($this->placeOrderException) !== null) {
            $errorMessage->asWarning();
            $errorMessage->withMessage(
                (string)__('Confirming your payment. Please complete any additional verification prompts.')
            );
            $errorMessage->withVisibilityDuration(7500);

            return $errorMessage;
        }

        $message = $this->placeOrderException instanceof LocalizedException
            ? $this->placeOrderException->getMessage()
            : (string)__('Something went wrong while processing your order. Please try again.');

        $errorMessage->withMessage($message);
        $errorMessage->withVisibilityDuration(7500);

        return $errorMessage;
    }

    /**
     * Block the processor's success-page redirect after a failed attempt, keeping the customer on
     * checkout in a retryable state (and, for 3DS, able to run the challenge in place).
     */
    public function canRedirect(): bool
    {
        return $this->placeOrderException === null;
    }

    /**
     * Find a StripeAuthenticationRequiredException in the exception chain, if any.
     *
     * Mirrors StashNextActionPlugin: the challenge exception may be wrapped by the time it reaches
     * the processor, so walk the previous-exception chain rather than checking the top level only.
     */
    protected function findAuthException(Throwable $exception): ?StripeAuthenticationRequiredException
    {
        for (; $exception !== null; $exception = $exception->getPrevious()) {
            if ($exception instanceof StripeAuthenticationRequiredException) {
                return $exception;
            }
        }

        return null;
    }
}
