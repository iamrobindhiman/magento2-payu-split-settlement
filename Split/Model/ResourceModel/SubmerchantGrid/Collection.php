<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SubmerchantGrid;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\Submerchant as SubmerchantModel;
use RKD\PayuSplit\Split\Service\SubmerchantRowPresenter;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Psr\Log\LoggerInterface;

/**
 * Grid collection over rkd_payu_submerchant for the A7-2 mapping admin view. Each loaded row
 * is decorated (in _afterLoad) with display-only columns: the child merchant key and MID
 * MASKED to last-4 (the full routing identifiers never appear in this list view — only on the
 * ACL-gated edit form), and human labels for the KYC status and active flag. The raw columns
 * remain the authoritative, sortable/searchable source; the computed columns are presentation
 * only. A dedicated grid collection (not the shared Submerchant\Collection the repository
 * uses) so the decoration never leaks into the read path A2/A5 rely on.
 */
class Collection extends AbstractCollection
{
    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param SubmerchantRowPresenter $presenter
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly SubmerchantRowPresenter $presenter,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SubmerchantModel::class, SubmerchantResource::class);
    }

    /**
     * Decorate each loaded row with the display-only computed columns.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        foreach ($this->getItems() as $row) {
            $row->setData('key_display', $this->presenter->maskSecret((string) $row->getData('payu_merchant_key')));
            $mid = $row->getData('payu_mid');
            $row->setData('mid_display', $this->presenter->maskSecret($mid === null ? null : (string) $mid));
            $row->setData('kyc_display', $this->presenter->kycLabel((string) $row->getData('kyc_status')));
            $row->setData('active_display', $this->presenter->activeLabel((int) $row->getData('is_active')));
            $row->setData('error_display', $this->presenter->errorSummary($row->getData('last_error')));
            $row->setData('esign_display', $this->presenter->esignLabel(
                (string) $row->getData('onboarding_state'),
                $row->getData('esign_sent_at') === null ? null : (string) $row->getData('esign_sent_at')
            ));
        }

        return $this;
    }
}
