<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Exception\PayuOnboardingCredentialsException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Service\OnboardingLinker;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Staff "Link PayU account" (decision 4): tie a vendor to a PayU sub-merchant that already exists.
 * Every check lives in OnboardingLinker; this only turns its answer into a message.
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key, audited by the linker in
 * the same transaction as the write. Both PayU calls it causes are reads.
 */
class Link extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param OnboardingLinker $linker
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly OnboardingLinker $linker,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('rkd_payu/submerchant/index');

        $sellerId = (int)$this->getRequest()->getParam('seller_id');
        [$account, $confirmed] = self::parseEntry((string)$this->getRequest()->getParam('payu_account', ''));
        try {
            $child = $this->linker->link($sellerId, $account, $confirmed);
            $this->messageManager->addSuccessMessage(__(
                'Seller %1 is linked to PayU sub-merchant MID ending %2 and queued; onboarding continues on '
                . 'the next scan (bank only if PayU has none, then the e-sign hand-off).',
                $sellerId,
                substr($child->getMid(), -4)
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (PayuOnboardingCredentialsException $e) {
            $this->messageManager->addErrorMessage(__(
                'PayU refused our onboarding credentials. Check the client id, secret and scopes in '
                . 'Stores > Configuration.'
            ));
        } catch (PayuOnboardingRequestException $e) {
            // Says what is wrong: an unreadable listing (a row without an id or email, short of its total) does
            // not clear on its own, and "try again" would hide that (wave-13 integrity Low).
            $this->messageManager->addErrorMessage(__('Could not check the account with PayU: %1', $e->getMessage()));
        } catch (\Throwable $t) {
            $this->logger->error('RKD_PayuSplit: link failed for seller ' . $sellerId . ': ' . get_class($t));
            $this->messageManager->addErrorMessage(__('Could not link the account. Please check the logs.'));
        }

        return $redirect;
    }

    /**
     * What staff typed: the MID or uuid, optionally followed by "confirm <seller ids>" when the account is
     * also claimed by another vendor's create (the refusal message tells them the exact text).
     *
     * @param string $entry
     * @return array{0: string, 1: int[]}
     */
    private static function parseEntry(string $entry): array
    {
        if (preg_match('/^\s*(\S+)\s+confirm\s+(\d+(?:\s*,\s*\d+)*)\s*$/i', $entry, $m) !== 1) {
            return [$entry, []];
        }
        return [$m[1], array_map('intval', preg_split('/\s*,\s*/', $m[2]) ?: [])];
    }
}
