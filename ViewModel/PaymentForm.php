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

namespace ParadoxLabs\StripeHyvaCheckout\ViewModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Payment\Model\MethodInterface;
use ParadoxLabs\Stripe\Helper\Data;
use ParadoxLabs\TokenBase\Block\Form\Cc;

class PaymentForm implements ArgumentInterface
{
    /**
     * @var Cc
     */
    protected $formBlock;

    /**
     * PaymentForm constructor.
     *
     * @param Data $helper
     * @param LayoutInterface $layout
     */
    public function __construct(
        protected readonly Data $helper,
        protected readonly LayoutInterface $layout,
    ) {
    }

    /**
     * Get the active payment method instance
     *
     * @param string $code
     * @return MethodInterface
     * @throws LocalizedException
     */
    public function getMethod(string $code): MethodInterface
    {
        return $this->helper->getMethodInstance($code);
    }

    /**
     * Get the active payment method form block
     *
     * @param string $code
     * @return Cc
     * @throws LocalizedException
     */
    public function getFormBlock(string $code): Cc
    {
        if (!isset($this->formBlock)) {
            $this->formBlock = $this->helper->getMethodFormBlock(
                $this->getMethod($code),
                $this->layout
            );
        }

        return $this->formBlock;
    }
}
