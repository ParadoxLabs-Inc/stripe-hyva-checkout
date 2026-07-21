<?php

declare(strict_types=1);

namespace ParadoxLabs\StripeHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Payment\DefaultOrderData;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magewirephp\Magewire\Component;
use ParadoxLabs\Stripe\Exception\StripeAuthenticationRequiredException;
use ParadoxLabs\StripeHyvaCheckout\Magewire\Payment\PlaceOrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Hyva Checkout PlaceOrderService
 */
class PlaceOrderServiceTest extends TestCase
{
    private PlaceOrderService $service;
    private CartManagementInterface|MockObject $cartManagement;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->payment = $this->createMock(QuotePayment::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('getId')->willReturn(7);

        $this->service = new PlaceOrderService(
            $this->cartManagement,
            new DefaultOrderData(),
        );
    }

    public function testPlaceOrderImportsWhitelistedPaymentData(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'token' => 'ctoken_abc123',
                'card_id' => '',
                'save' => 1,
            ],
        ]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_stripe',
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    'token' => 'ctoken_abc123',
                    'card_id' => '',
                    'save' => 1,
                ],
            ]);

        $this->cartManagement->expects($this->once())
            ->method('placeOrder')
            ->with(7, $this->payment)
            ->willReturn('42');

        $this->assertSame(42, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderStripsUnknownKeysAndForcesMethod(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'method' => 'checkmo',
                'token' => 'pi_123',
                'profile_id' => 'cus_evil',
                'payment_id' => 'pm_evil',
                'stripe_intent.id' => 'pi_evil',
                'save' => 0,
            ],
        ]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_stripe',
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    'token' => 'pi_123',
                    'save' => 0,
                ],
            ]);

        $this->cartManagement->method('placeOrder')->willReturn('100');

        $this->assertSame(100, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderWithNoPaymentDataImportsEmptyAdditionalData(): void
    {
        $this->service->getData()->setData([]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_stripe',
                PaymentInterface::KEY_ADDITIONAL_DATA => [],
            ]);

        $this->cartManagement->method('placeOrder')->willReturn('5');

        $this->assertSame(5, $this->service->placeOrder($this->quote));
    }

    /**
     * handleException must NOT rethrow: a rethrow aborts the Magewire request before the
     * order:place:{method}:error browser events reach the response, killing the 3DS recovery and
     * the spent-token scrub. Completing normally is what delivers them. The failed attempt must
     * also stop redirecting to the success page.
     */
    public function testHandleExceptionDoesNotRethrowAndBlocksRedirect(): void
    {
        $this->assertTrue($this->service->canRedirect());

        $this->service->handleException(
            new \Exception('gateway boom'),
            $this->createMock(Component::class),
            $this->quote
        );

        // Reaching this line proves no rethrow.
        $this->assertFalse($this->service->canRedirect());
    }

    public function testEvaluateCompletionAfterLocalizedFailureShowsRealMessage(): void
    {
        $this->service->handleException(
            new LocalizedException(__('Card declined.')),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())->method('withMessage')->with('Card declined.');
        $errorMessage->expects($this->never())->method('asWarning');

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);
        $resultFactory->expects($this->never())->method('createSuccess');

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
    }

    public function testEvaluateCompletionAfterGenericFailureMasksMessage(): void
    {
        $this->service->handleException(
            new \RuntimeException('PDO: connection refused at 10.0.0.5'),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with($this->logicalAnd(
                $this->stringContains('Something went wrong'),
                $this->logicalNot($this->stringContains('PDO'))
            ));

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
    }

    /**
     * A 3DS (requires_action) failure is softened: warning styling, a neutral message that never
     * leaks the challenge exception (client_secret/intent id), still a failure result so the
     * event-driven recovery runs without a premature success/redirect.
     */
    public function testEvaluateCompletionAfterAuthExceptionShowsSoftenedWarning(): void
    {
        $this->service->handleException(
            new StripeAuthenticationRequiredException(
                'pi_secret_do_not_leak',
                'pi_intent_id',
                new Phrase('Additional authentication is required. secret=pi_secret_do_not_leak')
            ),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())->method('asWarning');
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with($this->logicalAnd(
                $this->stringContains('Confirming your payment'),
                $this->logicalNot($this->stringContains('pi_secret_do_not_leak')),
                $this->logicalNot($this->stringContains('pi_intent_id'))
            ));

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);
        $resultFactory->expects($this->never())->method('createSuccess');

        // Still a failure: no redirect to the success page.
        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
        $this->assertFalse($this->service->canRedirect());
    }

    /**
     * A wrapped challenge exception (nested via getPrevious()) is still detected and softened.
     */
    public function testEvaluateCompletionDetectsWrappedAuthException(): void
    {
        $this->service->handleException(
            new \RuntimeException(
                'confirm failed',
                0,
                new StripeAuthenticationRequiredException('pi_secret', 'pi_id')
            ),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())->method('asWarning');
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with($this->stringContains('Confirming your payment'));

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
    }

    public function testEvaluateCompletionWithoutFailureUsesDefaultSuccess(): void
    {
        $success = $this->createMock(Success::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->expects($this->once())->method('createSuccess')->willReturn($success);
        $resultFactory->expects($this->never())->method('createErrorMessage');

        $this->assertSame($success, $this->service->evaluateCompletion($resultFactory, 42));
        $this->assertTrue($this->service->canRedirect());
    }
}
