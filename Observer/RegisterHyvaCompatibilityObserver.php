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

namespace ParadoxLabs\StripeHyvaCheckout\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Dispatcher for the `hyva_config_generate_before` event.
 */
class RegisterHyvaCompatibilityObserver implements ObserverInterface
{
    /**
     * RegisterHyvaCompatibilityObserver constructor.
     *
     * @param ComponentRegistrar $componentRegistrar
     */
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar
    ) {
    }

    public function execute(Observer $event): void
    {
        $config     = $event->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];

        if (!is_array($extensions)) {
            $extensions = [];
        }

        $moduleName = implode('_', array_slice(explode('\\', __CLASS__), 0, 2));

        $path = (string) $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);

        // ComponentRegistrar resolves registration.php's __DIR__ to a real path. Handle symlinks in case.
        if (str_starts_with($path, BP . DIRECTORY_SEPARATOR)) {
            $src = substr($path, strlen(BP) + 1);
        } else {
            $appCodePath = 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
                . str_replace('_', DIRECTORY_SEPARATOR, $moduleName);
            $symlink     = BP . DIRECTORY_SEPARATOR . $appCodePath;

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (is_link($symlink) && realpath($symlink) === realpath($path)) {
                $src = $appCodePath;
            } else {
                // Last resort: keep the historical behavior.
                $src = substr($path, strlen(BP) + 1);
            }
        }

        $extensions[] = ['src' => $src];

        $config->setData('extensions', $extensions);
    }
}
