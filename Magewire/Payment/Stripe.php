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

namespace ParadoxLabs\StripeHyvaCheckout\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magewirephp\Magewire\Component\Form;
use ParadoxLabs\Stripe\Helper\Data;
use ParadoxLabs\Stripe\Model\Service\Intents;
use ParadoxLabs\StripeHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use ParadoxLabs\TokenBase\Model\Card;
use Rakit\Validation\Validator;
use Throwable;

/**
 * Magewire component for the Stripe Payment Element payment form.
 *
 * Card entry happens client-side in the Payment Element (see scripts.phtml); this component owns
 * the stored-card list, the place-order evaluation, and the server-side quote data the client
 * needs at tokenization time (amount, currency, billing details), plus 3DS next-action recovery
 * after a requires_action place-order failure.
 */
class Stripe extends Form implements EvaluationInterface
{
    protected const METHOD_CODE = 'paradoxlabs_stripe';

    /**
     * @var array
     */
    protected $loader
        = [
            'billing_address_activated' => 'Loading payment form',
            'billing_address_saved' => 'Loading payment form',
            'billing_address_submitted' => 'Loading payment form',
        ];

    /**
     * The ConfirmationToken's billing_details are sourced from the quote billing address, and the
     * Element amount from the quote totals; any billing change can shift both, so push fresh quote
     * data to the browser whenever the billing address changes.
     *
     * @var string[]
     */
    protected $listeners
        = [
            'billing_address_activated' => 'refreshQuoteData',
            'billing_address_saved' => 'refreshQuoteData',
            'billing_address_submitted' => 'refreshQuoteData',
        ];

    /* Public component properties */
    public $storedCards = [];

    /* Protected property validation rule map */
    protected $rules
        = [
            'storedCards' => 'array',
        ];

    /**
     * @param \Rakit\Validation\Validator $validator
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Stripe\Helper\Data $helper
     * @param \ParadoxLabs\Stripe\Model\Service\Intents $intents
     * @param \ParadoxLabs\StripeHyvaCheckout\ViewModel\PaymentForm $formViewModel
     */
    public function __construct(
        Validator $validator,
        protected CheckoutSession $checkoutSession,
        protected CardRepositoryInterface $cardRepository,
        protected CartRepositoryInterface $quoteRepository,
        protected Data $helper,
        protected Intents $intents,
        protected PaymentForm $formViewModel,
    ) {
        parent::__construct($validator);
    }

    /**
     * Initialize component data on update
     */
    public function boot(): void
    {
        $this->loadSelectedCard();
    }

    /**
     * @throws LocalizedException
     */
    public function mount(): void
    {
        $this->loadStoredCards();
    }

    /**
     * Update component selected card based on the quote's assigned stored card
     *
     * @throws LocalizedException
     */
    protected function loadSelectedCard(): void
    {
        $payment = $this->getQuote()->getPayment();

        if ($payment->getData('tokenbase_id') === null) {
            return;
        }

        try {
            $card = $this->cardRepository->getById($payment->getData('tokenbase_id'));
        } catch (Throwable) {
            // The assigned card no longer exists (deleted since assignment); fall back to
            // new-card entry rather than 500-ing every Magewire request for the component.
            return;
        }

        if ($card->getMethod() === static::METHOD_CODE
            && (int)$card->getCustomerId() === (int)$this->getQuote()->getCustomerId()) {
            $this->addStoredCardToList($card);
        }
    }

    /**
     * Push fresh quote data (amount, currency, billing details) to the browser, so the Payment
     * Element and wallet sheet stay in sync with the quote.
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function refreshQuoteData(): void
    {
        $this->dispatchBrowserEvent(static::METHOD_CODE . 'QuoteUpdated', $this->getQuoteData());
    }

    /**
     * Get current quote data needed for client-side tokenization: the Element amount (in minor
     * units, base currency), currency code, and the billing details for the ConfirmationToken.
     *
     * The Element renders/validates against this amount, but the authoritative charge amount is
     * re-derived server-side at confirm; drift here can never change what is charged.
     *
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getQuoteData(): array
    {
        $quote = $this->getQuote();

        return [
            'amount' => (float)$this->helper->formatCurrencyAmount(
                $quote->getBaseGrandTotal(),
                $quote->getBaseCurrencyCode()
            ),
            'currency' => strtolower((string)$quote->getBaseCurrencyCode()),
            'billingDetails' => $this->getBillingDetails(),
        ];
    }

    /**
     * Get ConfirmationToken billing_details from the quote billing address.
     *
     * The Payment Element collects no billing fields (all 'never'), so these values are attached
     * to the token at creation; shape matches stripe.createConfirmationToken billing_details.
     *
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getBillingDetails(): array
    {
        $quote   = $this->getQuote();
        $address = $quote->getBillingAddress();
        $street  = $address->getStreet() ?: [''];

        return [
            'name' => trim($address->getFirstname() . ' ' . $address->getLastname()),
            'email' => $address->getEmail() ?: (string)$quote->getCustomerEmail(),
            'phone' => (string)$address->getTelephone(),
            'address' => [
                'line1' => $street[0] ?? '',
                'line2' => $street[1] ?? '',
                'city' => (string)$address->getCity(),
                'state' => (string)($address->getRegion() ?: $address->getRegionCode()),
                'postal_code' => (string)$address->getPostcode(),
                'country' => (string)$address->getCountryId(),
            ],
        ];
    }

    /**
     * Check for a pending 3DS next-action after a failed place-order attempt.
     *
     * On requires_action, StashNextActionPlugin persists the intent id/client_secret from the
     * thrown StripeAuthenticationRequiredException onto the quote payment (the order payment that
     * Method::persistIntent() wrote to is rolled back with the failed submit). Verify the intent
     * is genuinely awaiting action before handing the client_secret to the browser, then consume
     * the stash one-shot so the same secret is never served twice.
     *
     * The client_secret is scoped to this customer's own intent and session; it is never logged.
     *
     * @return array|null
     */
    public function getNextAction(): ?array
    {
        try {
            $quote        = $this->getQuote();
            $payment      = $quote->getPayment();
            $intentId     = (string)$payment->getAdditionalInformation(Intents::INTENT_ID);
            $clientSecret = (string)$payment->getAdditionalInformation(Intents::CLIENT_SECRET);

            if ($intentId === '' || $clientSecret === '') {
                return null;
            }

            // Consume the stash regardless of outcome; StashNextActionPlugin refills it if the
            // next attempt also comes back requires_action.
            $payment->setAdditionalInformation(Intents::INTENT_ID, null);
            $payment->setAdditionalInformation(Intents::CLIENT_SECRET, null);
            $this->quoteRepository->save($quote);

            $intent = $this->intents->getIntent((int)$this->helper->getCurrentStoreId(), $intentId);

            if ($intent->status !== 'requires_action') {
                return null;
            }

            return [
                'client_secret' => $clientSecret,
                'intent_id' => $intentId,
            ];
        } catch (Throwable $exception) {
            $this->helper->log(static::METHOD_CODE, 'Next-action lookup failed: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Get the current user's active quote
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getQuote(): CartInterface
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Determine whether checkout completion is allowed
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        $validationError = $resultFactory->createErrorMessage();
        $validationError->withMessage('There\'s an issue with your payment details. Please check the payment form.');
        $validationError->withVisibilityDuration(5000);

        $validation = $resultFactory->createValidation('validate' . static::METHOD_CODE);
        $validation->withFailureResult($validationError);

        return $validation;
    }

    /**
     * Get the active payment method instance
     *
     * @throws LocalizedException
     */
    protected function getMethod(): MethodInterface
    {
        return $this->formViewModel->getMethod(static::METHOD_CODE);
    }

    /**
     * Get the active payment method form block
     *
     * @throws LocalizedException
     */
    protected function getFormBlock(): Cc
    {
        return $this->formViewModel->getFormBlock(static::METHOD_CODE);
    }

    /**
     * @throws LocalizedException
     */
    protected function loadStoredCards(): void
    {
        /** @var Card $card */
        foreach ($this->getFormBlock()->getStoredCards() as $card) {
            $this->addStoredCardToList($card);
        }
    }

    protected function addStoredCardToList(CardInterface $card): void
    {
        $card = $card->getTypeInstance();
        $hash = (string)$card->getHash();

        $this->storedCards[$hash] = [
            'hash' => $hash,
            'label' => $card->getLabel(),
            'type' => $card->getType(),
        ];
    }
}
