<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ChildMerchant;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Exception\LocalizedException;

/**
 * What "Link PayU account" does with a merchant key already on file.
 *
 * A legacy row routes money with the key typed in for it, so a link normally has to describe THAT
 * account, or the uuid and the key would name two merchants. The exception is a key staff already
 * distrust: on a row they switched off with "Deactivate payouts", Link may take the key from PayU
 * instead. Nothing then ties the row to the account but the vendor's email, so the caller applies the
 * keyless email rule, and the link ends the on/off history — Reactivate can never switch the old key
 * back on. Without it a mistyped key had no exit: the form freezes a switched-off row's key, Reset
 * refuses while the vendor's own account is listed, and Link compared keys exactly (wave-10 integrity M-3).
 *
 * Only for a key that names NO account. The key on file is what holds its account against every other
 * row; replacing a live one would release it — another vendor could then adopt or be linked to an
 * account carrying this vendor's PAN and bank (wave-11 integrity M-1). So, with the same loose test
 * Reset uses, the replacement is refused if any listed account carries the key or the stored MID, or
 * if the listing does not show every key.
 */
class LinkKeyPolicy
{
    /**
     * @param PayoutSwitchHistory $switchHistory
     */
    public function __construct(
        private readonly PayoutSwitchHistory $switchHistory
    ) {
    }

    /**
     * @param Submerchant $row
     * @param ChildMerchant $child
     * @param array<int, array{uuid: string, mid: string, pgKey: string|null}> $listing PayU's complete listing
     * @return bool True when the link replaces the key on file with the account's
     * @throws LocalizedException When the key on file names a different account and may not be replaced
     */
    public function replacesKey(Submerchant $row, ChildMerchant $child, array $listing): bool
    {
        if (!$row->hasMerchantKey()) {
            return false;
        }
        $pgKey = $child->getPgKey();
        if ($pgKey !== null && hash_equals($row->getMerchantKey(), $pgKey)) {
            return false;
        }
        if ($pgKey !== null && !$row->isActive() && $this->switchHistory->isSwitchedOffByStaff((int)$row->getId())) {
            $this->assertKeyNamesNoAccount($row, $child, $listing);
            return true;
        }
        throw new LocalizedException(__(
            'That PayU account does not carry the merchant key already on file for seller %1, so it is not the '
            . 'account this vendor is paid through. If the key on file is wrong, switch payouts off with '
            . '"Deactivate payouts" first; Link then takes the key from PayU. Nothing was linked.',
            $row->getSellerId()
        ));
    }

    /**
     * The audit note for a link: the account linked, the key and MID it replaced (if it did), and any other
     * vendor whose create shares the account's email.
     *
     * @param Submerchant $row As it was before the link
     * @param ChildMerchant $child
     * @param bool $tiedByKey
     * @param int[] $sharedWith
     * @return string
     */
    public static function describe(Submerchant $row, ChildMerchant $child, bool $tiedByKey, array $sharedWith): string
    {
        $note = sprintf('seller %d; linked PayU sub-merchant MID ending %s', $row->getSellerId(), substr($child->getMid(), -4));
        if (!$tiedByKey && $row->hasMerchantKey()) {
            $note .= sprintf(
                '; replaced key ending %s, MID ending %s',
                substr($row->getMerchantKey(), -4),
                substr((string)$row->getPayuMid(), -4) ?: '-'
            );
        }
        return $note . ($sharedWith === [] ? '' : '; create email also recorded for seller(s) ' . implode(', ', $sharedWith));
    }

    /**
     * Against every OTHER listed account: a key or MID that already points at the account being linked frees
     * nothing — the row goes on holding it by uuid, MID and exact key — and refusing it stranded a typo'd key
     * next to the right MID, or a key wrong only in case (wave-12 integrity M-1).
     *
     * @param Submerchant $row
     * @param ChildMerchant $child
     * @param array<int, array{uuid: string, mid: string, pgKey: string|null}> $listing
     * @return void
     * @throws LocalizedException
     */
    private function assertKeyNamesNoAccount(Submerchant $row, ChildMerchant $child, array $listing): void
    {
        $key = trim($row->getMerchantKey());
        $mid = trim((string)$row->getPayuMid());
        foreach ($listing as $entry) {
            if (strcasecmp($entry['uuid'], $child->getUuid()) === 0) {
                continue;
            }
            if ($entry['pgKey'] === null || trim($entry['pgKey']) === '') {
                throw new LocalizedException(__(
                    'PayU\'s list of sub-merchants did not include every merchant key, so it cannot be ruled out that '
                    . 'seller %1\'s key on file is a real account. Nothing was linked.',
                    $row->getSellerId()
                ));
            }
            if (strcasecmp(trim($entry['pgKey']), $key) === 0 || ($mid !== '' && $entry['mid'] === $mid)) {
                throw new LocalizedException(__(
                    'The key on file for seller %1 belongs to a PayU sub-merchant that exists (MID ending %2). '
                    . 'Linking another account would release it, so nothing was linked. If the vendor really moved '
                    . 'to a new PayU account, this needs a person to sort out both accounts.',
                    $row->getSellerId(),
                    substr($entry['mid'], -4)
                ));
            }
        }
    }
}
