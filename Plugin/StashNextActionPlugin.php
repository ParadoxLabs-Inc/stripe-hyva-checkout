<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\StripeHyvaCheckout\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use ParadoxLabs\Stripe\Exception\StripeAuthenticationRequiredException;
use ParadoxLabs\Stripe\Model\Service\Intents;
use ParadoxLabs\StripeHyvaCheckout\Magewire\Payment\PlaceOrderService;
use Throwable;

/**
 * Persist the 3DS next-action (intent id + client_secret) onto the quote payment when place-order
 * fails with requires_action, so the payment component's getNextAction() can recover it on the
 * subsequent Magewire request.
 *
 * Method::persistIntent() writes stripe_intent.* to the ORDER payment during QuoteManagement
 * submit, but that transaction is rolled back on the requires_action throw — only the exception
 * carries the secret out. The Magewire place-order path (unlike REST/GraphQL) has no transport
 * adapter for it, so stash it on the quote here.
 *
 * Implemented as a plugin (not in PlaceOrderService itself) to avoid extending the Hyva
 * AbstractPlaceOrderService constructor, whose signature is not part of the documented API.
 */
class StashNextActionPlugin
{
    /**
     * StashNextActionPlugin constructor.
     *
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        protected readonly CartRepositoryInterface $quoteRepository
    ) {
    }

    /**
     * Stash the next-action from a requires_action failure; clear any stale stash first.
     *
     * The stash is one-shot per attempt: cleared before every placement so a secret left from an
     * abandoned challenge can never misfire the challenge UI on a later, unrelated failure.
     *
     * @param PlaceOrderService $subject
     * @param callable $proceed
     * @param Quote $quote
     * @return int
     * @throws Throwable
     */
    public function aroundPlaceOrder(
        PlaceOrderService $subject,
        callable $proceed,
        Quote $quote,
    ): int {
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation(Intents::INTENT_ID, null);
        $payment->setAdditionalInformation(Intents::CLIENT_SECRET, null);

        try {
            return $proceed($quote);
        } catch (Throwable $exception) {
            $authException = $this->findAuthException($exception);

            if ($authException !== null) {
                $payment->setAdditionalInformation(Intents::INTENT_ID, $authException->getIntentId());
                $payment->setAdditionalInformation(Intents::CLIENT_SECRET, $authException->getClientSecret());

                // The submit-failure observer has already saved the quote (clearing the spent
                // token); this save layers the next-action keys on top of that state.
                $this->quoteRepository->save($quote);
            }

            throw $exception;
        }
    }

    /**
     * Find a StripeAuthenticationRequiredException in the exception chain, if any.
     *
     * @param Throwable $exception
     * @return StripeAuthenticationRequiredException|null
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
