<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\Store;

/**
 * Tells a human when a vendor's onboarding needs one: it failed, or it is waiting on the
 * Schedule C click in PayU's dashboard.
 *
 * Two channels, because the money engine's existing alarms only reach log files and nobody
 * reads those: the admin bell (always works) and an email (works when SMTP does). Neither
 * may break the worker — a failed alert is logged and swallowed.
 *
 * Callers pass text that is already safe to show staff: no PAN, no bank number (N-5).
 */
class OnboardingAlerter
{
    private const TEMPLATE_ID = 'rkd_payu_onboarding_alert';
    private const SENDER_IDENTITY = 'general';

    /**
     * @param NotifierInterface $notifier
     * @param TransportBuilder $transportBuilder
     * @param BackendUrl $backendUrl
     * @param OnboardingConfig $config
     * @param Logger $logger
     */
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly TransportBuilder $transportBuilder,
        private readonly BackendUrl $backendUrl,
        private readonly OnboardingConfig $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param int $sellerId
     * @param string $shop Vendor's display name, for the human reading the alert
     * @param string $headline One line: what happened
     * @param string $detail What to do about it
     * @param bool $critical True for a failure, false for "action needed"
     * @return void
     */
    public function alert(int $sellerId, string $shop, string $headline, string $detail, bool $critical): void
    {
        $url = $this->gridUrl();
        $title = sprintf('PayU onboarding: %s — %s (seller #%d)', $headline, $shop, $sellerId);

        try {
            $critical
                ? $this->notifier->addCritical($title, $detail, $url)
                : $this->notifier->addMajor($title, $detail, $url);
        } catch (\Throwable $t) {
            $this->logger->error('PayU onboarding: admin notification failed', [
                'seller_id' => $sellerId,
                'error' => $t->getMessage(),
            ]);
        }

        $this->sendEmail($sellerId, $shop, $headline, $detail, $title, $url);
    }

    /**
     * @param int $sellerId
     * @param string $shop
     * @param string $headline
     * @param string $detail
     * @param string $subject
     * @param string $url
     * @return void
     */
    private function sendEmail(
        int $sellerId,
        string $shop,
        string $headline,
        string $detail,
        string $subject,
        string $url
    ): void {
        $recipient = $this->config->getAlertEmail();
        if ($recipient === '') {
            $this->logger->warning('PayU onboarding: no alert email configured, bell notification only', [
                'seller_id' => $sellerId,
            ]);
            return;
        }

        try {
            $this->transportBuilder
                ->setTemplateIdentifier(self::TEMPLATE_ID)
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars([
                    'subject' => $subject,
                    'seller_id' => (string)$sellerId,
                    'shop' => $shop,
                    'headline' => $headline,
                    'detail' => $detail,
                    'grid_url' => $url,
                ])
                ->setFromByScope(self::SENDER_IDENTITY, Store::DEFAULT_STORE_ID)
                ->addTo($recipient);
            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Throwable $t) {
            $this->logger->error('PayU onboarding: alert email failed', [
                'seller_id' => $sellerId,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * @return string
     */
    private function gridUrl(): string
    {
        try {
            return $this->backendUrl->getUrl('rkd_payu/submerchant/index', ['_nosecret' => true]);
        } catch (\Throwable $t) {
            return '';
        }
    }
}
