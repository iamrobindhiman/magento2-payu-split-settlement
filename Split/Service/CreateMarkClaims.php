<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Who else could own a PayU child made under an email: the one rule, for the worker's adoption,
 * staff Link and staff Forget alike (wave-9 integrity M-1).
 *
 * A row's create mark (payu_email) says "a create went out with this email". It is a claim on any
 * child PayU holds under that email, until the row is proven to have got its own account from it:
 * its stored uuid is a child in PayU's listing carrying that same email. Only then is the mark
 * explained and dead. A row that merely HOLDS some account is not enough — linked by hand to a
 * different account, its own lost create may still be the child under the mark, and treating it as
 * settled would let another vendor adopt an account PayU verified with this vendor's PAN.
 *
 * A uuid the listing does not show leaves the mark unexplained: fail closed, a person decides.
 */
class CreateMarkClaims
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int $sellerId The row asking; never counted
     * @param string $email
     * @param array<int, array{uuid: string, email: string}> $children PayU's listing
     * @return int[] Seller ids of the OTHER rows whose recorded create is a live claim on $email
     */
    public function otherClaimants(int $sellerId, string $email, array $children): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }
        $rows = $this->collectionFactory->create()
            ->addFieldToFilter('payu_email', $email)
            ->addFieldToFilter('seller_id', ['neq' => $sellerId]);

        $claimants = [];
        foreach ($rows as $row) {
            if (!self::isExplained((string)$row->getData('payu_uuid'), (string)$row->getData('payu_email'), $children)) {
                $claimants[] = (int)$row->getData('seller_id');
            }
        }
        return $claimants;
    }

    /**
     * A person linking an account other vendors also claim must say, by seller id, that it is not theirs —
     * after checking its PAN in PayU's dashboard. Told only afterwards, in the audit, they would learn of
     * the contest once the bank was already attached for good (wave-10 integrity M-1).
     *
     * @param int[] $claimants Other live claimants on the account's email
     * @param int[] $confirmed Seller ids staff confirmed
     * @param int $sellerId The vendor being linked
     * @param string $account What staff entered
     * @param string $mid
     * @return void
     * @throws LocalizedException Until $confirmed names exactly $claimants
     */
    public static function assertConfirmed(array $claimants, array $confirmed, int $sellerId, string $account, string $mid): void
    {
        $claimants = array_values(array_unique(array_map('intval', $claimants)));
        $confirmed = array_values(array_unique(array_map('intval', $confirmed)));
        sort($claimants);
        sort($confirmed);
        if ($claimants === [] || $claimants === $confirmed) {
            return;
        }
        $ids = implode(',', $claimants);
        throw new LocalizedException(__(
            'Seller(s) #%1 also sent a PayU create with this account\'s email, so the account (MID ending %2) may '
            . 'be theirs. Check the PAN on it in PayU\'s dashboard. If it is seller %3\'s, press "Link PayU '
            . 'account" again and enter: %4 confirm %5. Nothing was linked.',
            $ids,
            substr($mid, -4),
            $sellerId,
            $account,
            $ids
        ));
    }

    /**
     * Whether a row's own recorded create went out with $email.
     *
     * @param string|null $mark The row's payu_email
     * @param string $email
     * @return bool
     */
    public static function isOwnClaim(?string $mark, string $email): bool
    {
        return trim($email) !== '' && $mark !== null && strcasecmp(trim($mark), trim($email)) === 0;
    }

    /**
     * Whether a mark is settled by the row's own account: its uuid is a listed child with that email.
     *
     * @param string $uuid
     * @param string $mark
     * @param array<int, array{uuid: string, email: string}> $children
     * @return bool
     */
    public static function isExplained(string $uuid, string $mark, array $children): bool
    {
        if ($uuid === '') {
            return false;
        }
        foreach ($children as $child) {
            if (strcasecmp($child['uuid'], $uuid) === 0) {
                return strcasecmp(trim($child['email']), trim($mark)) === 0;
            }
        }
        return false;
    }
}
