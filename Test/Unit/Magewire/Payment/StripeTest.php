<?php

declare(strict_types=1);

namespace ParadoxLabs\StripeHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Validation;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use ParadoxLabs\Stripe\Helper\Data;
use ParadoxLabs\Stripe\Model\Service\Intents;
use ParadoxLabs\StripeHyvaCheckout\Magewire\Payment\Stripe;
use ParadoxLabs\StripeHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface as TokenBaseCardRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rakit\Validation\Validator;
use Stripe\PaymentIntent;

/**
 * Unit tests for the Stripe Magewire payment component
 */
class StripeTest extends TestCase
{
    private Stripe $component;
    private CheckoutSession|MockObject $checkoutSession;
    private CartRepositoryInterface|MockObject $quoteRepository;
    private TokenBaseCardRepositoryInterface|MockObject $cardRepository;
    private Data|MockObject $helper;
    private Intents|MockObject $intents;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cardRepository = $this->createMock(TokenBaseCardRepositoryInterface::class);
        $this->helper = $this->createMock(Data::class);
        $this->intents = $this->createMock(Intents::class);

        $this->payment = $this->createMock(QuotePayment::class);

        // getBaseGrandTotal/getBaseCurrencyCode/getCustomerEmail are magic (no concrete declaration
        // on Quote); stub getData() and let the real DataObject::__call route them to it.
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getPayment', 'getBillingAddress'])
            ->getMock();
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);

        $this->component = new Stripe(
            new Validator(),
            $this->checkoutSession,
            $this->cardRepository,
            $this->quoteRepository,
            $this->helper,
            $this->intents,
            $this->createMock(PaymentForm::class),
        );
    }

    /**
     * boot tests
     */

    /**
     * A tokenbase_id pointing at a card the customer has since deleted must not brick the
     * component: getById throwing has to fall back to new-card entry, never 500 the request.
     */
    public function testBootSwallowsRepositoryErrorForDeletedCard(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('999');

        $this->cardRepository->method('getById')
            ->with('999')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->component->boot();

        $this->assertSame([], $this->component->storedCards);
    }

    /**
     * getNextAction tests
     */

    public function testGetNextActionReturnsNullWithEmptyStash(): void
    {
        $this->payment->method('getAdditionalInformation')->willReturn(null);

        $this->quoteRepository->expects($this->never())->method('save');
        $this->intents->expects($this->never())->method('getIntent');

        $this->assertNull($this->component->getNextAction());
    }

    public function testGetNextActionReturnsChallengeDataWhenIntentRequiresAction(): void
    {
        $this->stashIntent('pi_123', 'pi_123_secret_456');
        $this->helper->method('getCurrentStoreId')->willReturn('2');

        $this->intents->method('getIntent')
            ->with(2, 'pi_123')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_123',
                'status' => 'requires_action',
            ]));

        // One-shot: stash must be cleared and persisted regardless of outcome.
        $this->expectStashCleared();

        $this->assertSame(
            [
                'client_secret' => 'pi_123_secret_456',
                'intent_id' => 'pi_123',
            ],
            $this->component->getNextAction()
        );
    }

    public function testGetNextActionReturnsNullWhenIntentNotAwaitingAction(): void
    {
        $this->stashIntent('pi_123', 'pi_123_secret_456');
        $this->helper->method('getCurrentStoreId')->willReturn('1');

        $this->intents->method('getIntent')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_123',
                'status' => 'requires_payment_method',
            ]));

        $this->expectStashCleared();

        $this->assertNull($this->component->getNextAction());
    }

    public function testGetNextActionSwallowsLookupErrorsAndLogsWithoutSecret(): void
    {
        $this->stashIntent('pi_123', 'pi_123_secret_456');
        $this->helper->method('getCurrentStoreId')->willReturn('1');

        $this->intents->method('getIntent')
            ->willThrowException(new \RuntimeException('API connection failed'));

        $this->helper->expects($this->once())
            ->method('log')
            ->with(
                'paradoxlabs_stripe',
                $this->logicalAnd(
                    $this->stringContains('Next-action lookup failed'),
                    $this->logicalNot($this->stringContains('pi_123_secret_456'))
                )
            );

        $this->assertNull($this->component->getNextAction());
    }

    /**
     * loadNextAction tests
     *
     * Magewire 1.x discards method return values, so the browser can only receive the
     * next-action (or quote data) through a dispatched browser event.
     */

    public function testLoadNextActionDispatchesChallengeDataAsBrowserEvent(): void
    {
        $this->stashIntent('pi_123', 'pi_123_secret_456');
        $this->helper->method('getCurrentStoreId')->willReturn('2');

        $this->intents->method('getIntent')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_123',
                'status' => 'requires_action',
            ]));

        $this->component->loadNextAction();

        $this->assertSame(
            [
                [
                    'event' => 'paradoxlabs_stripeNextAction',
                    'data' => [
                        'client_secret' => 'pi_123_secret_456',
                        'intent_id' => 'pi_123',
                    ],
                ],
            ],
            $this->component->getBrowserEvents()
        );
    }

    public function testLoadNextActionDispatchesEmptyEventWhenNothingPending(): void
    {
        $this->payment->method('getAdditionalInformation')->willReturn(null);

        $this->component->loadNextAction();

        $this->assertSame(
            [
                [
                    'event' => 'paradoxlabs_stripeNextAction',
                    'data' => [],
                ],
            ],
            $this->component->getBrowserEvents()
        );
    }

    /**
     * refreshQuoteData tests
     */

    public function testRefreshQuoteDataDispatchesBillingDetailsAsBrowserEvent(): void
    {
        $this->quote->method('getData')->willReturnMap([
            ['base_grand_total', null, 30.0],
            ['base_currency_code', null, 'USD'],
        ]);
        $this->quote->method('getBillingAddress')->willReturn($this->buildBillingAddress());
        $this->helper->method('formatCurrencyAmount')->willReturn(3000);

        $this->component->refreshQuoteData();

        $events = $this->component->getBrowserEvents();

        $this->assertCount(1, $events);
        $this->assertSame('paradoxlabs_stripeQuoteUpdated', $events[0]['event']);
        $this->assertSame('Jamie Doe', $events[0]['data']['billingDetails']['name']);
    }

    /**
     * getQuoteData tests
     */

    public function testGetQuoteDataReturnsAmountCurrencyAndBillingDetails(): void
    {
        $this->quote->method('getData')->willReturnMap([
            ['base_grand_total', null, 107.5000],
            ['base_currency_code', null, 'USD'],
        ]);
        $this->quote->method('getBillingAddress')->willReturn($this->buildBillingAddress());

        $this->helper->method('formatCurrencyAmount')
            ->with(107.5000, 'USD')
            ->willReturn(10750);

        $result = $this->component->getQuoteData();

        $this->assertSame(10750.0, $result['amount']);
        $this->assertSame('usd', $result['currency']);
        $this->assertSame(
            [
                'name' => 'Jamie Doe',
                'email' => 'jamie@example.com',
                'phone' => '5551234567',
                'address' => [
                    'line1' => '123 Main St',
                    'line2' => 'Apt 4',
                    'city' => 'Lancaster',
                    'state' => 'Pennsylvania',
                    'postal_code' => '17601',
                    'country' => 'US',
                ],
            ],
            $result['billingDetails']
        );
    }

    public function testGetQuoteDataFallsBackToCustomerEmailAndRegionCode(): void
    {
        $address = $this->buildBillingAddress([
            'getEmail' => null,
            'getRegion' => null,
            'getRegionCode' => 'PA',
            'getStreet' => ['123 Main St'],
        ]);

        $this->quote->method('getData')->willReturnMap([
            ['base_grand_total', null, 10.0],
            ['base_currency_code', null, 'USD'],
            ['customer_email', null, 'customer@example.com'],
        ]);
        $this->quote->method('getBillingAddress')->willReturn($address);
        $this->helper->method('formatCurrencyAmount')->willReturn(1000);

        $result = $this->component->getQuoteData();

        $this->assertSame('customer@example.com', $result['billingDetails']['email']);
        $this->assertSame('PA', $result['billingDetails']['address']['state']);
        $this->assertSame('', $result['billingDetails']['address']['line2']);
    }

    /**
     * evaluateCompletion tests
     */

    public function testEvaluateCompletionRegistersMethodValidator(): void
    {
        $errorMessage = $this->createMock(ErrorMessage::class);
        $validation = $this->createMock(Validation::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);
        $resultFactory->expects($this->once())
            ->method('createValidation')
            ->with('validateparadoxlabs_stripe')
            ->willReturn($validation);

        $validation->expects($this->once())
            ->method('withFailureResult')
            ->with($errorMessage);

        $this->assertSame($validation, $this->component->evaluateCompletion($resultFactory));
    }

    /**
     * Stub the quote payment's next-action stash.
     */
    private function stashIntent(string $intentId, string $clientSecret): void
    {
        $this->payment->method('getAdditionalInformation')
            ->willReturnMap([
                [Intents::INTENT_ID, $intentId],
                [Intents::CLIENT_SECRET, $clientSecret],
            ]);
    }

    /**
     * Expect the stash consumed one-shot: both keys nulled and the quote saved.
     */
    private function expectStashCleared(): void
    {
        $this->payment->expects($this->exactly(2))
            ->method('setAdditionalInformation')
            ->with(
                $this->logicalOr(Intents::INTENT_ID, Intents::CLIENT_SECRET),
                $this->isNull()
            )
            ->willReturn($this->payment);

        $this->quoteRepository->expects($this->once())
            ->method('save')
            ->with($this->quote);
    }

    /**
     * Build a billing address mock; override individual getters via $overrides.
     */
    private function buildBillingAddress(array $overrides = []): Address|MockObject
    {
        $defaults = [
            'getFirstname' => 'Jamie',
            'getLastname' => 'Doe',
            'getEmail' => 'jamie@example.com',
            'getTelephone' => '5551234567',
            'getStreet' => ['123 Main St', 'Apt 4'],
            'getCity' => 'Lancaster',
            'getRegion' => 'Pennsylvania',
            'getRegionCode' => null,
            'getPostcode' => '17601',
            'getCountryId' => 'US',
        ];

        $address = $this->createMock(Address::class);

        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $address->method($method)->willReturn($value);
        }

        return $address;
    }
}
