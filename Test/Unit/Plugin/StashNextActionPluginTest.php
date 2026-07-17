<?php

declare(strict_types=1);

namespace ParadoxLabs\StripeHyvaCheckout\Test\Unit\Plugin;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use ParadoxLabs\Stripe\Exception\StripeAuthenticationRequiredException;
use ParadoxLabs\Stripe\Model\Service\Intents;
use ParadoxLabs\StripeHyvaCheckout\Magewire\Payment\PlaceOrderService;
use ParadoxLabs\StripeHyvaCheckout\Plugin\StashNextActionPlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StashNextActionPlugin
 */
class StashNextActionPluginTest extends TestCase
{
    private StashNextActionPlugin $plugin;
    private CartRepositoryInterface|MockObject $quoteRepository;
    private PlaceOrderService|MockObject $subject;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    /**
     * Captured [key => value] pairs from setAdditionalInformation calls, in call order.
     *
     * @var array
     */
    private array $stashWrites = [];

    protected function setUp(): void
    {
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->subject = $this->createMock(PlaceOrderService::class);
        $this->payment = $this->createMock(QuotePayment::class);

        $this->payment->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value = null) {
                $this->stashWrites[] = [$key, $value];

                return $this->payment;
            });

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getPayment')->willReturn($this->payment);

        $this->plugin = new StashNextActionPlugin($this->quoteRepository);
    }

    public function testSuccessClearsStaleStashAndReturnsOrderId(): void
    {
        $this->quoteRepository->expects($this->never())->method('save');

        $result = $this->plugin->aroundPlaceOrder(
            $this->subject,
            fn (Quote $quote): int => 42,
            $this->quote
        );

        $this->assertSame(42, $result);
        $this->assertSame(
            [
                [Intents::INTENT_ID, null],
                [Intents::CLIENT_SECRET, null],
            ],
            $this->stashWrites
        );
    }

    public function testAuthExceptionStashesNextActionAndRethrows(): void
    {
        $exception = new StripeAuthenticationRequiredException('pi_123_secret_456', 'pi_123');

        $this->quoteRepository->expects($this->once())
            ->method('save')
            ->with($this->quote);

        try {
            $this->plugin->aroundPlaceOrder(
                $this->subject,
                function () use ($exception): int {
                    throw $exception;
                },
                $this->quote
            );

            $this->fail('Exception was not rethrown');
        } catch (StripeAuthenticationRequiredException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [
                [Intents::INTENT_ID, null],
                [Intents::CLIENT_SECRET, null],
                [Intents::INTENT_ID, 'pi_123'],
                [Intents::CLIENT_SECRET, 'pi_123_secret_456'],
            ],
            $this->stashWrites
        );
    }

    public function testNestedAuthExceptionIsFoundInPreviousChain(): void
    {
        $authException = new StripeAuthenticationRequiredException('seti_123_secret_456', 'seti_123');
        $wrapped = new CouldNotSaveException(__('Could not save.'), $authException);

        $this->quoteRepository->expects($this->once())
            ->method('save')
            ->with($this->quote);

        try {
            $this->plugin->aroundPlaceOrder(
                $this->subject,
                function () use ($wrapped): int {
                    throw $wrapped;
                },
                $this->quote
            );

            $this->fail('Exception was not rethrown');
        } catch (CouldNotSaveException $caught) {
            $this->assertSame($wrapped, $caught);
        }

        $this->assertSame([Intents::INTENT_ID, 'seti_123'], $this->stashWrites[2]);
        $this->assertSame([Intents::CLIENT_SECRET, 'seti_123_secret_456'], $this->stashWrites[3]);
    }

    public function testUnrelatedExceptionDoesNotStashAndRethrows(): void
    {
        $exception = new LocalizedException(__('Card declined.'));

        $this->quoteRepository->expects($this->never())->method('save');

        try {
            $this->plugin->aroundPlaceOrder(
                $this->subject,
                function () use ($exception): int {
                    throw $exception;
                },
                $this->quote
            );

            $this->fail('Exception was not rethrown');
        } catch (LocalizedException $caught) {
            $this->assertSame($exception, $caught);
        }

        // Only the pre-attempt clear; no stash writes for a non-3DS failure.
        $this->assertSame(
            [
                [Intents::INTENT_ID, null],
                [Intents::CLIENT_SECRET, null],
            ],
            $this->stashWrites
        );
    }
}
