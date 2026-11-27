<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\OnboardingStepFailedException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ChildMerchant;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Staff "Link PayU account": tie a vendor to a PayU sub-merchant that already exists, instead of
 * letting onboarding create one (decision 4, 2026-09-24).
 *
 * Two cases need it, and onboarding deliberately refuses both on its own:
 *   - a hand-typed legacy mapping (merchant key, no uuid) — onboarding never creates a second
 *     account for a vendor who already has one;
 *   - PayU already holds an account with the vendor's email that ALLYNOR never created (D15) —
 *     it may be someone else's, so only a person may say it is theirs.
 *
 * Every check the worker makes before trusting an account is made here too, against PayU and not
 * against what staff typed: the account must be in OUR parent's listing, read back as a child of
 * our aggregator, carry the key already on file (legacy), and belong to no other seller. Nothing is
 * sent to PayU — both calls are reads. The row then goes to QUEUED with its identity stored, so the
 * ordinary worker takes it from there (approval + role checks, bank only if PayU has none, e-sign
 * hand-off). It never sets is_active.
 */
class OnboardingLinker
{
    /** Rows a person may link: never-onboarded (legacy) or stopped. Anything else is the worker's. */
    private const LINKABLE_STATES = [Submerchant::STATE_NOT_STARTED, Submerchant::STATE_FAILED];

    private const UUID_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';
    private const MID_PATTERN = '/^\d{4,20}$/';

    /**
     * @param SubmerchantRepositoryInterface $repository
     * @param SubmerchantResource $resource
     * @param CollectionFactory $collectionFactory
     * @param VendorOnboardingDataReader $reader
     * @param PayuOnboardingClient $client
     * @param OnboardingResponseParser $parser
     * @param SettlementAuditLogger $auditLogger
     * @param DateTime $dateTime
     * @param CreateMarkClaims $markClaims
     * @param LinkKeyPolicy $keyPolicy
     * @param ExistingChildFinder $childFinder
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly SubmerchantResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly VendorOnboardingDataReader $reader,
        private readonly PayuOnboardingClient $client,
        private readonly OnboardingResponseParser $parser,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly DateTime $dateTime,
        private readonly CreateMarkClaims $markClaims,
        private readonly LinkKeyPolicy $keyPolicy,
        private readonly ExistingChildFinder $childFinder
    ) {
    }

    /**
     * @param int $sellerId
     * @param string $account The PayU MID or account uuid, as staff copied it from PayU's dashboard
     * @param int[] $confirmedClaimants Other sellers staff confirmed this account is NOT, after checking its PAN
     * @return ChildMerchant The account now linked
     * @throws LocalizedException When the link is refused; nothing is written
     * @throws PayuOnboardingRequestException When PayU could not be read; nothing is written
     */
    public function link(int $sellerId, string $account, array $confirmedClaimants = []): ChildMerchant
    {
        $row = $this->linkableRow($sellerId);
        $account = trim($account);
        $isMid = $this->isMid($account);
        $listing = $this->readListing();
        $entry = $this->entryInOurListing($listing, $account, $isMid);
        $child = $this->readOurChild($entry['uuid']);

        $tiedByKey = $row->hasMerchantKey() && !$this->keyPolicy->replacesKey($row, $child, $listing);
        $sharedWith = $this->assertEmailBelongsHere($row, $entry['email'], $listing, $tiedByKey);
        CreateMarkClaims::assertConfirmed($sharedWith, $confirmedClaimants, $sellerId, $account, $child->getMid());
        $this->assertNotLinkedElsewhere($child, $sellerId);
        $this->write($row, $child, $sharedWith, $tiedByKey);

        return $child;
    }

    /**
     * @param int $sellerId
     * @return Submerchant
     * @throws LocalizedException
     */
    private function linkableRow(int $sellerId): Submerchant
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null) {
            throw new LocalizedException(__('Seller %1 has no PayU mapping row to link.', $sellerId));
        }
        if ($row->getPayuUuid() !== null) {
            throw new LocalizedException(__('Seller %1 is already linked to a PayU account.', $sellerId));
        }
        if (!in_array($row->getOnboardingState(), self::LINKABLE_STATES, true)) {
            throw new LocalizedException(__(
                'Seller %1 is being onboarded (%2). Only a vendor whose onboarding failed, or who was never '
                . 'onboarded, can be linked by hand.',
                $sellerId,
                $row->getOnboardingState()
            ));
        }
        if (!$this->reader->isApprovedSeller($sellerId)) {
            throw new LocalizedException(__('Seller %1 is not an approved seller.', $sellerId));
        }
        if ($this->reader->hasAffiliateRecord($sellerId)) {
            throw new LocalizedException(__(
                'Customer %1 also holds an affiliate account. A customer can be a seller or an affiliate, '
                . 'never both, so no PayU account was linked.',
                $sellerId
            ));
        }
        return $row;
    }

    /**
     * @param string $account
     * @return bool True for a MID, false for a uuid
     * @throws LocalizedException When it is neither
     */
    private function isMid(string $account): bool
    {
        if (preg_match(self::MID_PATTERN, $account) === 1) {
            return true;
        }
        if (preg_match(self::UUID_PATTERN, $account) === 1) {
            return false;
        }
        throw new LocalizedException(__('Enter the PayU MID (digits) or the account uuid.'));
    }

    /**
     * @param string $uuid
     * @return ChildMerchant
     * @throws LocalizedException
     */
    private function readOurChild(string $uuid): ChildMerchant
    {
        $read = $this->client->fetchChildMerchant($uuid);
        $this->assertRead($read->isSuccess(), $read->isUnauthorized(), 'read the PayU sub-merchant');
        $child = $this->parser->parseChildMerchant($read);
        if ($child === null) {
            throw new LocalizedException(__('PayU returned no account when reading the sub-merchant.'));
        }
        if (!$child->isParentedCorrectly()) {
            throw new LocalizedException(__('%1', OnboardingSteps::orphanMessage()));
        }
        return $child;
    }

    /**
     * Only an account in our parent's own listing can be linked; a uuid or MID from anywhere else
     * is refused before it is ever read.
     *
     * @param array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}> $listing
     * @param string $account
     * @param bool $isMid
     * @return array{uuid: string, email: string} The account's listing entry
     * @throws LocalizedException
     */
    private function entryInOurListing(array $listing, string $account, bool $isMid): array
    {
        foreach ($listing as $entry) {
            if ($isMid ? $entry['mid'] === $account : strcasecmp($entry['uuid'], $account) === 0) {
                return ['uuid' => $entry['uuid'], 'email' => trim($entry['email'])];
            }
        }
        throw new LocalizedException(__(
            'No sub-merchant with that %1 is under our PayU parent account. Check the value in PayU\'s dashboard.',
            $isMid ? 'MID' : 'uuid'
        ));
    }

    /**
     * @return array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}>
     * @throws LocalizedException
     */
    private function readListing(): array
    {
        // The complete, every-row-accounted-for reader onboarding and Reset use: a listing that drops a row or
        // falls short of its own total cannot rule out that a key on file is live (wave-12 integrity M-2).
        try {
            return $this->childFinder->listChildren();
        } catch (OnboardingStepFailedException $e) {
            throw new LocalizedException(__('PayU refused to list our PayU sub-merchants: %1', $e->getMessage()));
        }
    }

    /**
     * The account's email must be this vendor's (wave-3 I-F3).
     *
     * A child whose create response was lost belongs to the vendor whose row recorded that email
     * (payu_email), even though no uuid is stored against it yet — linking it elsewhere would attach
     * this vendor's bank to an account PayU verified with someone else's PAN. Another row's mark counts
     * only while it is a live claim (CreateMarkClaims): a row whose own account is that child has a dead
     * one (wave-9 integrity Low).
     *
     * A row with no key on file has nothing else tying it to the account, so the email must be the
     * vendor's CURRENT one (wave-5 integrity F2), or the one its OWN create was sent with — the account
     * the worker would adopt for it. When that mark is shared with another live claimant the worker
     * stops for a person, and this is the person's way through: staff check the PAN in PayU's dashboard
     * and link whichever vendor it belongs to, confirming by seller id that it is not the other's
     * (CreateMarkClaims::assertConfirmed); the other claimant is named in the audit (wave-9 integrity
     * M-1, wave-10 M-1). A row that is not itself a claimant still never takes another's lost create.
     * A legacy row whose key the account carries is already tied by it.
     *
     * @param Submerchant $row
     * @param string $accountEmail
     * @param array<int, array{uuid: string, email: string}> $listing
     * @param bool $tiedByKey The account carries the key on file
     * @return int[] Other live claimants on the account's email (non-empty only when this row is one too)
     * @throws LocalizedException
     */
    private function assertEmailBelongsHere(Submerchant $row, string $accountEmail, array $listing, bool $tiedByKey): array
    {
        $isClaimant = CreateMarkClaims::isOwnClaim($row->getPayuEmail(), $accountEmail);
        $others = $this->markClaims->otherClaimants($row->getSellerId(), $accountEmail, $listing);
        if ($others !== [] && !$isClaimant) {
            throw new LocalizedException(__(
                'That PayU account was created for seller #%1, whose onboarding is waiting to adopt it. '
                . 'Nothing was linked.',
                $others[0]
            ));
        }
        if ($tiedByKey || $isClaimant) {
            return $others;
        }
        $current = strtolower(trim($this->reader->readEmail($row->getSellerId())));
        if ($accountEmail === '' || strtolower($accountEmail) !== $current) {
            throw new LocalizedException(__(
                'That PayU account is not registered with seller %1\'s email, so nothing ties it to this '
                . 'vendor. Nothing was linked.',
                $row->getSellerId()
            ));
        }
        return $others;
    }

    /**
     * One PayU account belongs to one seller — on uuid, MID and key, against every OTHER row.
     *
     * @param ChildMerchant $child
     * @param int $sellerId
     * @return void
     * @throws LocalizedException
     */
    private function assertNotLinkedElsewhere(ChildMerchant $child, int $sellerId): void
    {
        $fields = ['payu_uuid'];
        $conditions = [['eq' => $child->getUuid()]];
        if ($child->getMid() !== '') {
            $fields[] = 'payu_mid';
            $conditions[] = ['eq' => $child->getMid()];
        }
        if ($child->getPgKey() !== null && $child->getPgKey() !== '') {
            $fields[] = 'payu_merchant_key';
            $conditions[] = ['eq' => $child->getPgKey()];
        }
        $others = $this->collectionFactory->create()
            ->addFieldToFilter($fields, $conditions)
            ->addFieldToFilter('seller_id', ['neq' => $sellerId]);
        if ($others->getSize() > 0) {
            throw new LocalizedException(__(
                'That PayU account is already linked to seller #%1.',
                (int)$others->getFirstItem()->getData('seller_id')
            ));
        }
    }

    /**
     * Compare-and-set on the state and the empty uuid the checks above were made against, with the
     * audit row in the same transaction: a concurrent Retry or link leaves this one writing nothing.
     *
     * @param Submerchant $row
     * @param ChildMerchant $child
     * @param int[] $sharedWith Other live claimants on the account's email, for the audit
     * @param bool $tiedByKey The account carries the key on file (else its key is written from PayU)
     * @return void
     * @throws LocalizedException
     */
    private function write(Submerchant $row, ChildMerchant $child, array $sharedWith, bool $tiedByKey): void
    {
        $bind = [
            'payu_uuid' => $child->getUuid(),
            'payu_mid' => $child->getMid() !== '' ? $child->getMid() : null,
            'onboarding_state' => Submerchant::STATE_QUEUED,
            'attempts' => 0,
            'last_error' => null,
            'error_class' => null,
            'next_attempt_at' => null,
        ];
        if (!$tiedByKey && $child->getPgKey() !== null) {
            $bind['payu_merchant_key'] = $child->getPgKey();
        }

        // Pinned on everything the checks read: a key typed into the form, a mark written by a Retry, or the
        // row re-pointed at another seller while PayU was being read leaves this writing nothing (wave-9
        // security L-2, wave-10 security Low).
        $key = $row->hasMerchantKey() ? $row->getMerchantKey() : null;
        $mark = $row->getPayuEmail();
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $written = (int)$connection->update(
                $this->resource->getMainTable(),
                $bind,
                [
                    'entity_id = ?' => (int)$row->getId(),
                    'seller_id = ?' => $row->getSellerId(),
                    'payu_uuid IS NULL',
                    'onboarding_state = ?' => $row->getOnboardingState(),
                    'locked_until IS NULL OR locked_until < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
                    // A key written from PayU goes only onto a row that is off: a Reactivate landing meanwhile, or
                    // a keyless row somehow active, would route money to it before bank and e-sign (wave-11/13
                    // integrity Lows). A row tied by its own key keeps whatever it had.
                    'is_active = ?' => $tiedByKey ? (int)$row->isActive() : 0,
                ] + SubmerchantResource::pinned('payu_merchant_key', $key, true)
                    + SubmerchantResource::pinned('payu_email', $mark)
            );
            if ($written !== 1) {
                throw new LocalizedException(__(
                    'Seller %1 changed while this was being checked. Reload the grid and try again.',
                    $row->getSellerId()
                ));
            }
            // A legacy row keeps the account its key already named, so its on/off history carries on; a keyless
            // row, or one whose key was taken from PayU, gets a newly tied account, which ends it (wave-8
            // integrity L-2, wave-10 M-3).
            $this->auditLogger->record(
                $tiedByKey ? AuditLog::ACTION_ONBOARDING_LINK_LEGACY : AuditLog::ACTION_ONBOARDING_LINK,
                AuditLog::ENTITY_SUBMERCHANT_ROW,
                (int)$row->getId(),
                null,
                $row->getOnboardingState(),
                Submerchant::STATE_QUEUED,
                LinkKeyPolicy::describe($row, $child, $tiedByKey, $sharedWith)
            );
            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            throw $t;
        }
    }

    /**
     * @param bool $success
     * @param bool $unauthorized
     * @param string $what
     * @return void
     * @throws LocalizedException
     */
    private function assertRead(bool $success, bool $unauthorized, string $what): void
    {
        if ($success) {
            return;
        }
        throw new LocalizedException($unauthorized
            ? __('PayU refused to %1 (401). Check the onboarding credentials and scopes.', $what)
            : __('PayU would not %1. Try again shortly.', $what));
    }
}
