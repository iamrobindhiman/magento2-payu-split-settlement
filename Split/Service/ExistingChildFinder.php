<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\OnboardingStepFailedException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;

/**
 * Before onboarding creates a PayU child, this reads PayU's list of our children and answers one
 * question: did an EARLIER create of ours already make one for this vendor?
 *
 * Adoption exists only for crash recovery: we asked PayU to create, and never heard back. So it
 * matches on the email that create was sent with (stored before the call), and a vendor we never
 * sent is not adopted — a PayU account that merely shares their email may belong to someone else,
 * and linking it would pay this vendor's money to that account. Staff can still link one by hand
 * (OnboardingLinker).
 *
 * A listing we cannot fully read is a retry, never a green light to create: if we cannot see what
 * PayU holds we cannot rule out a duplicate, and a PayU child cannot be deleted.
 */
class ExistingChildFinder
{
    /**
     * @param PayuOnboardingClient $client
     * @param OnboardingResponseParser $parser
     * @param CollectionFactory $collectionFactory
     * @param CreateMarkClaims $markClaims
     */
    public function __construct(
        private readonly PayuOnboardingClient $client,
        private readonly OnboardingResponseParser $parser,
        private readonly CollectionFactory $collectionFactory,
        private readonly CreateMarkClaims $markClaims
    ) {
    }

    /**
     * @param Submerchant $row
     * @param string $currentEmail The vendor's email as it stands now
     * @return string|null The uuid to adopt, or null when a create is safe
     * @throws OnboardingStepFailedException When a person has to decide
     * @throws PayuOnboardingRequestException When the listing cannot be trusted yet
     */
    public function findAdoptable(Submerchant $row, string $currentEmail): ?string
    {
        $sentEmail = $row->getPayuEmail();
        $children = $this->readCompleteChildren($sentEmail === null);

        $matches = $sentEmail === null ? [] : array_column(
            $this->notHeldElsewhere($this->childrenWithEmail($children, $sentEmail), $row),
            'uuid'
        );
        if (count($matches) > 1) {
            throw OnboardingStepFailedException::business(
                'PayU holds more than one sub-merchant with the email this vendor was created with. Pick the '
                . 'right one in PayU\'s dashboard before retrying.'
            );
        }
        if ($matches !== []) {
            $this->assertMarkIsOnlyOurs($row, (string)$sentEmail, $children);
            return $matches[0];
        }

        // No child from a create of ours. A child under the vendor's CURRENT email is then one ALLYNOR
        // never recorded sending — checked even when an older mark exists, or a stale mark would let a
        // second account be created beside it (wave-5 integrity F1). Only a person may say it is theirs.
        if ($this->notHeldElsewhere($this->childrenWithEmail($children, $currentEmail), $row) !== []) {
            throw OnboardingStepFailedException::business(
                'PayU already holds a sub-merchant with this vendor\'s email, and ALLYNOR never created '
                . 'one for them. It may belong to someone else, so it was not linked automatically. '
                . 'Check it in PayU\'s dashboard, then use "Link PayU account" if it is theirs.'
            );
        }
        return null;
    }

    /**
     * @param array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}> $children
     * @param string $email
     * @return array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}>
     */
    private function childrenWithEmail(array $children, string $email): array
    {
        return array_values(array_filter(
            $children,
            static fn (array $c): bool => strcasecmp(trim($c['email']), trim($email)) === 0
        ));
    }

    /**
     * Children that NO other row holds by uuid or merchant key. One another seller already holds came
     * from that seller's own create, adoption or link — it is not this vendor's (wave-7 integrity F1,
     * wave-8 L-1: the same rule for the mark match and the current-email check). Not by MID: a MID on a
     * legacy row is typed by hand and never checked against PayU, too weak to let a create through
     * beside an account that may be this vendor's (wave-8 L-3).
     *
     * @param array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}> $children
     * @param Submerchant $row
     * @return array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}>
     */
    private function notHeldElsewhere(array $children, Submerchant $row): array
    {
        return array_values(array_filter($children, function (array $child) use ($row): bool {
            $fields = ['payu_uuid'];
            $conditions = [['eq' => $child['uuid']]];
            if ($child['pgKey'] !== null && $child['pgKey'] !== '') {
                $fields[] = 'payu_merchant_key';
                $conditions[] = ['eq' => $child['pgKey']];
            }
            return $this->collectionFactory->create()
                ->addFieldToFilter($fields, $conditions)
                ->addFieldToFilter('seller_id', ['neq' => $row->getSellerId()])
                ->getSize() === 0;
        }));
    }

    /**
     * Adoption rests on the email our create was sent with. If ANOTHER vendor's row records a create
     * sent with the same email (a vendor changed theirs and someone new took the old one), the child
     * could be either's: stop for a person rather than attach this vendor's bank to it (wave-5 integrity F2).
     *
     * Another row's mark is dead only when its own account is the child under that email (wave-8 M-1);
     * merely holding SOME account is not enough — linked elsewhere, its lost create may be this child
     * (wave-9 integrity M-1). Staff resolve a shared claim with "Link PayU account".
     *
     * @param Submerchant $row
     * @param string $sentEmail
     * @param array<int, array{uuid: string, email: string}> $children
     * @return void
     */
    private function assertMarkIsOnlyOurs(Submerchant $row, string $sentEmail, array $children): void
    {
        $others = $this->markClaims->otherClaimants($row->getSellerId(), $sentEmail, $children);
        if ($others !== []) {
            throw OnboardingStepFailedException::business(sprintf(
                'The PayU sub-merchant created with this vendor\'s old email may belong to seller #%d, whose '
                . 'create was sent with the same email. Check the PAN on it in PayU\'s dashboard, then use '
                . '"Link PayU account" on whichever vendor it belongs to.',
                $others[0]
            ));
        }
    }

    /**
     * Every child under our parent, from a fresh and complete listing — for staff actions that must
     * see all of PayU's side before changing ours.
     *
     * @return array<int, array{uuid: string, mid: string, email: string, pgKey: string|null}>
     * @throws OnboardingStepFailedException|PayuOnboardingRequestException When PayU cannot be read in full
     */
    public function listChildren(): array
    {
        return array_map(
            static fn (array $c): array => [
                'uuid' => $c['uuid'], 'mid' => $c['mid'], 'email' => trim($c['email']), 'pgKey' => $c['pgKey'],
            ],
            $this->readCompleteChildren(true)
        );
    }

    /**
     * The listing, complete, every row accounted for — the one reader for the create gate and for staff
     * actions alike. The parser drops a row it cannot read (no uuid) while the completeness check counts
     * it; if one is dropped, "not in the list" means nothing and the caller must not decide (wave-7
     * security L-1, wave-8 integrity M-2).
     *
     * @param bool $mayBeEmpty
     * @return array<int, array{uuid: string, mid: string, name: string, email: string, pgKey: string|null, onboardingCompleted: bool}>
     */
    private function readCompleteChildren(bool $mayBeEmpty): array
    {
        $response = $this->readListing();
        $this->assertListingComplete($response, $mayBeEmpty);
        $children = $this->parser->parseSubAccountList($response);

        $raw = $response->body()['child_merchants']['product_accounts'] ?? [];
        if (count($children) !== (is_array($raw) ? count($raw) : 0)) {
            throw new PayuOnboardingRequestException(
                'PayU\'s sub-merchant listing had a row without an account id; not safe to decide without it.'
            );
        }
        // One uuid, one account: a listing naming two under one uuid cannot say which one a check skipped or
        // matched (wave-13 integrity Low).
        if (count(array_unique(array_map('strtolower', array_column($children, 'uuid')))) !== count($children)) {
            throw new PayuOnboardingRequestException(
                'PayU\'s sub-merchant listing had two rows with the same account id; not safe to decide.'
            );
        }
        // Every decision here matches on email. A row without one cannot be matched to a mark, so a lost
        // create listed before its email would read as "nothing made" and a second child would follow
        // (wave-9 integrity L-2): treat it like a row we could not read.
        foreach ($children as $child) {
            if (trim($child['email']) === '') {
                throw new PayuOnboardingRequestException(
                    'PayU\'s sub-merchant listing had a row without an email; not safe to decide without it.'
                );
            }
        }
        return $children;
    }

    /**
     * Whether PayU says our parent has no sub-merchants at all — a fresh read, for Retry.
     *
     * @return bool
     * @throws OnboardingStepFailedException|PayuOnboardingRequestException When PayU cannot be read
     */
    public function parentHasNoChildren(): bool
    {
        $total = $this->readListing()->body()['total_childs'] ?? null;
        return is_numeric($total) && (int)$total === 0;
    }

    /**
     * @return OnboardingApiResponse
     */
    private function readListing(): OnboardingApiResponse
    {
        $response = $this->client->listSubAccounts();
        if ($response->isUnauthorized()) {
            throw OnboardingStepFailedException::configuration(
                'PayU refused the sub-account listing (401). Check the onboarding client id, secret '
                . 'and that the fetch_child_merchants scope is granted.'
            );
        }
        if (!$response->isSuccess()) {
            throw new PayuOnboardingRequestException(
                'Could not read PayU\'s list of sub-merchants: ' . $response->errorSummary()
            );
        }
        return $response;
    }


    /**
     * The listing carries total_childs; if the rows returned fall short, we are looking at part
     * of it, and "not in the list" would mean nothing. Without total_childs we cannot tell, so
     * that is a retry too.
     *
     * The listing is NOT paged: on UAT (2026-09-24, 9 children) it ignored page, per_page,
     * page_size, pageSize, limit and offset alike and returned every row. If PayU ever starts
     * paging at scale, this check is what stops us creating a duplicate instead.
     *
     * A parent with no children may come back with no rows array at all. That is accepted only
     * for a vendor we have never sent ($mayBeEmpty): once a create has gone out, PayU holds at
     * least that child, and an empty answer contradicts our own record.
     *
     * @param OnboardingApiResponse $response
     * @param bool $mayBeEmpty
     * @return void
     */
    private function assertListingComplete(OnboardingApiResponse $response, bool $mayBeEmpty): void
    {
        // total_childs sits beside child_merchants at the top of the body (recorded UAT response).
        $totalRaw = $response->body()['total_childs'] ?? null;
        if (!is_numeric($totalRaw)) {
            throw new PayuOnboardingRequestException(
                'PayU\'s sub-merchant listing carried no total, so a partial list cannot be ruled out.'
            );
        }
        $total = (int)$totalRaw;
        if ($total === 0) {
            if (!$mayBeEmpty) {
                throw new PayuOnboardingRequestException(
                    'PayU\'s sub-merchant listing is empty, but a create was already sent for this vendor.'
                );
            }
            return;
        }

        $block = $response->body()['child_merchants'] ?? null;
        $rows = is_array($block) ? ($block['product_accounts'] ?? null) : null;
        if (!is_array($rows)) {
            throw new PayuOnboardingRequestException('PayU\'s sub-merchant listing came back in an unexpected shape.');
        }
        if ($total > count($rows)) {
            throw new PayuOnboardingRequestException(sprintf(
                'PayU\'s sub-merchant listing returned %d of %d accounts; not safe to decide without the rest.',
                count($rows),
                $total
            ));
        }
    }
}
