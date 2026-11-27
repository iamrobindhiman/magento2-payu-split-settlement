<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use Fab\MarketplaceRegister\Model\Security\SensitiveValueCodec;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Model\SettlementBankDetail;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Webkul\Affiliate\Helper\Data as AffiliateHelper;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as SellerCollectionFactory;

/**
 * Reads what PayU needs about a vendor out of Webkul's marketplace_userdata.
 *
 * Always the store_id = 0 row: that is where registration writes the business, PAN and bank
 * fields, and the one row every seller is guaranteed to have (marketplace-userdata-crud.md).
 *
 * PAN and the settlement account number are decrypted here, in memory, only when a call is
 * about to be made. Nothing read here is logged.
 */
class VendorOnboardingDataReader
{
    /** Webkul's is_seller value for an approved vendor. 3 and 4 are disapproved/denied. */
    private const SELLER_APPROVED = 1;

    /**
     * @param SellerCollectionFactory $sellerCollectionFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param SensitiveValueCodec $sensitiveValueCodec
     * @param AffiliateHelper $affiliateHelper
     * @param BusinessEntityResolver $entityResolver
     * @param OnboardingConfig $config
     */
    public function __construct(
        private readonly SellerCollectionFactory $sellerCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SensitiveValueCodec $sensitiveValueCodec,
        private readonly AffiliateHelper $affiliateHelper,
        private readonly BusinessEntityResolver $entityResolver,
        private readonly OnboardingConfig $config
    ) {
    }

    /**
     * Whether the vendor is approved right now. Re-checked by the cron before every PayU
     * call: a vendor can be disapproved between approval and the next run.
     *
     * @param int $sellerId
     * @return bool
     */
    public function isApprovedSeller(int $sellerId): bool
    {
        $row = $this->sellerRow($sellerId);
        return $row !== null && (int)$row->getData('is_seller') === self::SELLER_APPROVED;
    }

    /**
     * Whether the customer holds an affiliate record of any status. The role rule is
     * affiliate OR vendor, never both — a pending affiliate application counts.
     *
     * @param int $sellerId
     * @return bool
     */
    public function hasAffiliateRecord(int $sellerId): bool
    {
        return is_array($this->affiliateHelper->isAffiliateUser((string)$sellerId));
    }

    /**
     * A name staff will recognise in an alert. No decryption involved.
     *
     * @param int $sellerId
     * @return string
     */
    public function readShopName(int $sellerId): string
    {
        $row = $this->sellerRow($sellerId);
        foreach (['business_name', 'shop_title', 'shop_url'] as $field) {
            $value = trim((string)$row?->getData($field));
            if ($value !== '') {
                return $value;
            }
        }
        return sprintf('Seller #%d', $sellerId);
    }

    /**
     * @param int $sellerId
     * @return string|null
     */
    public function readLegalType(int $sellerId): ?string
    {
        $legalType = trim((string)$this->sellerRow($sellerId)?->getData('legal_type'));
        return $legalType === '' ? null : $legalType;
    }

    /**
     * The vendor's customer email, the one PayU is sent as the account email.
     *
     * @param int $sellerId
     * @return string
     */
    public function readEmail(int $sellerId): string
    {
        return (string)$this->readCustomer($sellerId)[0];
    }

    /**
     * @param int $sellerId
     * @return ChildMerchantDraft
     */
    public function readDraft(int $sellerId): ChildMerchantDraft
    {
        $row = $this->sellerRow($sellerId) ?? new DataObject();
        [$email, $customerName] = $this->readCustomer($sellerId);

        $contactName = trim((string)$row->getData('contact_full_name'));
        $businessName = trim((string)$row->getData('business_name'));

        return new ChildMerchantDraft(
            $contactName !== '' ? $contactName : $customerName,
            $email,
            self::normaliseMobile((string)$row->getData('contact_number')),
            $businessName !== '' ? $businessName : trim((string)$row->getData('shop_title')),
            (int)$this->entityResolver->entityId($this->readLegalType($sellerId)),
            $this->sensitiveValueCodec->decode('pan_number', (string)$row->getData('pan_number')),
            (string)$row->getData('pan_name'),
            $this->config->getMonthlyExpectedVolume()
        );
    }

    /**
     * @param int $sellerId
     * @return SettlementBankDetail
     */
    public function readBank(int $sellerId): SettlementBankDetail
    {
        $row = $this->sellerRow($sellerId) ?? new DataObject();
        return new SettlementBankDetail(
            $this->sensitiveValueCodec->decode('bank_account_number', (string)$row->getData('bank_account_number')),
            $this->sensitiveValueCodec->decode('bank_ifsc', (string)$row->getData('bank_ifsc')),
            (string)$row->getData('bank_account_holder')
        );
    }

    /**
     * The OTP-verified number is stored with the 91 country code; PayU wants 10 digits.
     *
     * @param string $raw
     * @return string
     */
    public static function normaliseMobile(string $raw): string
    {
        $digits = (string)preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }
        return $digits;
    }

    /**
     * @param int $sellerId
     * @return DataObject|null
     */
    private function sellerRow(int $sellerId): ?DataObject
    {
        $row = $this->sellerCollectionFactory->create()
            ->addFieldToFilter('seller_id', $sellerId)
            ->addFieldToFilter('store_id', 0)
            ->setPageSize(1)
            ->getFirstItem();
        return $row->getId() ? $row : null;
    }

    /**
     * @param int $sellerId
     * @return array{0: string, 1: string} [email, full name]
     */
    private function readCustomer(int $sellerId): array
    {
        try {
            $customer = $this->customerRepository->getById($sellerId);
        } catch (NoSuchEntityException $e) {
            return ['', ''];
        }
        return [
            (string)$customer->getEmail(),
            trim($customer->getFirstname() . ' ' . $customer->getLastname()),
        ];
    }
}
