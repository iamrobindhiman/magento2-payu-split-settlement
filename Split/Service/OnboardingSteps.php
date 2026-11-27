<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\OnboardingStepFailedException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingNotSentException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\ChildMerchant;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The two PayU-facing onboarding steps, and the rules that keep each from doing damage.
 *
 * Both calls are irreversible — a child merchant cannot be deleted, and the bank detail is
 * accepted once — so each step leans towards NOT calling:
 *   - all the vendor's data, bank included, is validated before the create call;
 *   - before creating, PayU's list of our children is searched and a match is adopted, so a
 *     crash between PayU's create and our save cannot produce a second child (ExistingChildFinder);
 *   - a failed or partial listing is a retry, never a green light to create;
 *   - before attaching the bank, PayU's own attempt counter is read, not our state column;
 *   - a child PayU reports outside our aggregator is recorded (so it is never re-created)
 *     and stopped, and never given a merchant key.
 *
 * Failures are signalled, not handled: OnboardingStepFailedException for anything retrying
 * cannot fix, PayuOnboardingRequestException for anything it can. OnboardingProcessor owns
 * the state machine and decides what each means.
 */
class OnboardingSteps
{
    /** PayU's wording when the one-shot bank detail has already been accepted. */
    private const BANK_ALREADY_SUBMITTED = 'already submitted';

    private const HTTP_CLIENT_ERROR_MIN = 400;
    private const HTTP_SERVER_ERROR_MIN = 500;

    /** A timeout or rate limit: PayU may or may not have acted, so the create-sent mark stays. */
    private const AMBIGUOUS_CLIENT_ERRORS = [408, 429];

    /**
     * @param SubmerchantRepositoryInterface $repository
     * @param VendorOnboardingDataReader $reader
     * @param OnboardingPreflight $preflight
     * @param PayuOnboardingClient $client
     * @param OnboardingResponseParser $parser
     * @param DateTime $dateTime
     * @param Logger $logger
     * @param ExistingChildFinder $childFinder
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly VendorOnboardingDataReader $reader,
        private readonly OnboardingPreflight $preflight,
        private readonly PayuOnboardingClient $client,
        private readonly OnboardingResponseParser $parser,
        private readonly DateTime $dateTime,
        private readonly Logger $logger,
        private readonly ExistingChildFinder $childFinder
    ) {
    }

    /**
     * Make sure a child merchant exists at PayU for this vendor and its identity is stored.
     *
     * @param Submerchant $row
     * @param callable $keepLease Called right before the create; throws if the lease was lost
     * @return void
     */
    public function ensureChildMerchant(Submerchant $row, callable $keepLease): void
    {
        // A retried vendor whose child already exists (uuid stored) never goes near create.
        if ($row->getPayuUuid() !== null) {
            return;
        }
        if ($row->isLegacyMapping()) {
            throw OnboardingStepFailedException::business(
                'This vendor has a hand-entered PayU merchant key. Onboarding never creates a second '
                . 'account for them; link the existing one instead.'
            );
        }

        $sellerId = $row->getSellerId();
        $draft = $this->reader->readDraft($sellerId);
        $problems = $this->preflight->check(
            $draft,
            $this->reader->readLegalType($sellerId),
            $this->reader->readBank($sellerId)
        );
        if ($problems !== []) {
            throw OnboardingStepFailedException::business(
                'Not sent to PayU — fix the vendor record first: ' . implode(' ', $problems)
            );
        }

        $existing = $this->childFinder->findAdoptable($row, $draft->getEmail());
        if ($existing !== null) {
            $child = $this->fetchChild($existing);
            $this->logger->info('PayU onboarding: adopted the child an earlier create made', ['seller_id' => $sellerId]);
        } else {
            $this->assertEarlierCreateHasSettled($row);
            $keepLease();
            // Commit-before-call: record the email this create is sent with. If the create lands at
            // PayU but our side never hears back, the retry adopts on THIS email, even if the vendor
            // changes theirs in the meantime.
            $response = $this->sendCreate($row, $draft);
            $this->assertAccepted($response, 'create the PayU sub-merchant');
            $child = $this->parser->parseChildMerchant($response);
            if ($child === null) {
                // Not safe to create again blind; the next run adopts it from the listing.
                throw new PayuOnboardingRequestException('PayU accepted the create but returned no account.');
            }
        }

        $this->assertNotLinkedElsewhere($child, $sellerId);
        $this->storeIdentity($row, $child);
    }

    /**
     * Send the create, recording first that it went out (payu_email) — and taking that record back
     * when PayU's answer proves nothing was made.
     *
     * payu_email is what lets a retry adopt a child whose create response was lost, and what makes an
     * empty listing untrustworthy for this vendor. So:
     *   - never sent (no token — PayuOnboardingNotSentException): the mark goes back to what it was;
     *   - a definite refusal (4xx other than a timeout or rate limit): the mark goes back to what it was;
     *   - a transport error, 5xx, 408, 429, or an accepted create: the new mark stays.
     * "What it was", not NULL: an earlier create whose answer was lost may still be outstanding, and
     * its email is the only way to adopt it (wave-4 correctness F2). Left set after a certain "nothing
     * was made", it would strand the vendor on a parent with no children (wave-3/4 integrity F2/F1).
     *
     * @param Submerchant $row
     * @param ChildMerchantDraft $draft
     * @return OnboardingApiResponse
     */
    private function sendCreate(Submerchant $row, ChildMerchantDraft $draft): OnboardingApiResponse
    {
        // Commit-before-call: if the create lands at PayU but our side never hears back, the retry
        // adopts on THIS email, even if the vendor changes theirs in the meantime.
        $before = $row->getPayuEmail();
        $row->setData('payu_email', $draft->getEmail());
        $this->markAttempt($row);

        try {
            $response = $this->client->createChildMerchant($draft);
        } catch (PayuOnboardingNotSentException $e) {
            $this->restoreCreateMark($row, $before);
            throw $e;
        }
        $status = $response->httpStatus();
        if ($status >= self::HTTP_CLIENT_ERROR_MIN && $status < self::HTTP_SERVER_ERROR_MIN
            && !in_array($status, self::AMBIGUOUS_CLIENT_ERRORS, true)
        ) {
            $this->restoreCreateMark($row, $before);
        }
        return $response;
    }

    /**
     * @param Submerchant $row
     * @param string|null $before
     * @return void
     */
    private function restoreCreateMark(Submerchant $row, ?string $before): void
    {
        $row->setData('payu_email', $before);
        $this->repository->save($row);
    }

    /**
     * Make sure PayU holds the vendor's settlement bank account, sending it only if it does not.
     *
     * @param Submerchant $row
     * @param callable $keepLease Called right before the bank PUT; throws if the lease was lost
     * @return void
     */
    public function ensureBankAttached(Submerchant $row, callable $keepLease): void
    {
        $uuid = $row->getPayuUuid();
        if ($uuid === null) {
            throw OnboardingStepFailedException::business('No PayU account id is stored for this vendor.');
        }

        // Ask PayU, not our state column: a crash after PayU accepted the bank but before we
        // saved leaves our row at CREATED while PayU's counter already says 1 (N-4).
        $child = $this->fetchChild($uuid);
        if (!$child->isParentedCorrectly()) {
            throw OnboardingStepFailedException::orphan(self::orphanMessage());
        }
        if ($child->hasBankAttached()) {
            $this->assertBankNotRejected($child);
            return;
        }

        $bank = $this->reader->readBank($row->getSellerId());
        $problems = $this->preflight->checkBank($bank);
        if ($problems !== []) {
            throw OnboardingStepFailedException::business(
                'Bank details not sent — fix the vendor record first: ' . implode(' ', $problems)
            );
        }

        $keepLease();
        $this->markAttempt($row);
        $response = $this->client->attachBankDetail(
            $uuid,
            $bank->getAccountNumber(),
            $bank->getIfsc(),
            $bank->getHolderName()
        );
        if (!$response->isSuccess() && !$this->isBankAlreadySubmitted($response)) {
            $this->assertAccepted($response, 'attach the settlement bank account');
        }
    }

    /**
     * @return string
     */
    public static function orphanMessage(): string
    {
        return 'PayU reports this sub-merchant is NOT under our aggregator (an orphan). It must never be '
            . 'used for payments and cannot be deleted. Raise it with PayU; do not retry until they confirm.';
    }

    /**
     * One PayU account belongs to one seller. Checked on uuid, MID and merchant key, so a
     * hand-typed legacy mapping (key and MID, no uuid) is protected too.
     *
     * @param ChildMerchant $child
     * @param int $sellerId
     * @return void
     */
    private function assertNotLinkedElsewhere(ChildMerchant $child, int $sellerId): void
    {
        $owner = $this->repository->getByPayuUuid($child->getUuid())
            ?? $this->repository->getByPayuMidOrKey($child->getMid(), $child->getPgKey());
        if ($owner !== null && $owner->getSellerId() !== $sellerId) {
            throw OnboardingStepFailedException::business(sprintf(
                'That PayU sub-merchant is already linked to seller #%d.',
                $owner->getSellerId()
            ));
        }
    }

    /**
     * PayU counts a bank submission whether or not it verified, so "attached" alone is not
     * enough: a rejected account must stop here rather than be reported to staff as done.
     *
     * @param ChildMerchant $child
     * @return void
     */
    private function assertBankNotRejected(ChildMerchant $child): void
    {
        $status = (string)$child->getBankVerificationStatus();
        if (preg_match('/fail|reject|invalid|mismatch/i', $status)) {
            throw OnboardingStepFailedException::business(sprintf(
                'PayU rejected the settlement bank account (status "%s"; %d bank attempts left). Fix the bank '
                . 'details with the vendor, then update them in PayU\'s dashboard.',
                $status,
                $child->getBankUpdateAttemptLeft()
            ));
        }
    }

    /**
     * @param string $uuid
     * @return ChildMerchant
     */
    private function fetchChild(string $uuid): ChildMerchant
    {
        $response = $this->client->fetchChildMerchant($uuid);
        $this->assertAccepted($response, 'read the PayU sub-merchant');
        $child = $this->parser->parseChildMerchant($response);
        if ($child === null) {
            throw new PayuOnboardingRequestException('PayU returned no account when reading the sub-merchant.');
        }
        return $child;
    }

    /**
     * Persist who the child is, before anything else can go wrong.
     *
     * An orphan's uuid is stored too — that is what stops a retry from creating yet another
     * account — but never its key, so nothing can route money to it.
     *
     * @param Submerchant $row
     * @param ChildMerchant $child
     * @return void
     */
    private function storeIdentity(Submerchant $row, ChildMerchant $child): void
    {
        $row->setData('payu_uuid', $child->getUuid());
        $row->setData('payu_mid', $child->getMid() !== '' ? $child->getMid() : null);

        if (!$child->isParentedCorrectly()) {
            $this->repository->save($row);
            throw OnboardingStepFailedException::orphan(self::orphanMessage());
        }

        if ($child->getPgKey() !== null) {
            $row->setData('payu_merchant_key', $child->getPgKey());
        }
        $this->repository->save($row);
    }

    /**
     * 401 = our credentials; any other 4xx = PayU refused this vendor's data. 5xx never gets
     * here — the client throws it as retryable.
     *
     * @param OnboardingApiResponse $response
     * @param string $what
     * @return void
     */
    private function assertAccepted(OnboardingApiResponse $response, string $what): void
    {
        if ($response->isSuccess()) {
            return;
        }
        if ($response->isUnauthorized()) {
            throw OnboardingStepFailedException::configuration(sprintf(
                'PayU refused to %s (401). Check the onboarding client id, secret and scopes.',
                $what
            ));
        }
        if (in_array($response->httpStatus(), self::AMBIGUOUS_CLIENT_ERRORS, true)) {
            // A timeout or rate limit is not a refusal: PayU may have acted, so retry later with the
            // create-sent mark kept, rather than failing the vendor (wave-5 integrity F4).
            throw new PayuOnboardingRequestException(sprintf(
                'PayU answered HTTP %d when asked to %s — retrying later.',
                $response->httpStatus(),
                $what
            ));
        }
        if ($response->httpStatus() >= self::HTTP_CLIENT_ERROR_MIN) {
            throw OnboardingStepFailedException::business(sprintf(
                'PayU refused to %s — %s',
                $what,
                $response->errorSummary()
            ));
        }
        throw new PayuOnboardingRequestException(sprintf(
            'PayU answered HTTP %d when asked to %s.',
            $response->httpStatus(),
            $what
        ));
    }

    /**
     * @param OnboardingApiResponse $response
     * @return bool
     */
    private function isBankAlreadySubmitted(OnboardingApiResponse $response): bool
    {
        foreach ($response->errorMessages() as $message) {
            if (stripos($message, self::BANK_ALREADY_SUBMITTED) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * A mark with no account means an earlier create went out and we never heard what it made. PayU's
     * listing may not show that child yet, so "no match" is not proof of nothing: within the window
     * Retry and Forget already respect, creating again could give this vendor a second, undeletable
     * account (wave-10 integrity M-2). Wait instead — a retry, never a create. An unknown time counts
     * as recent.
     *
     * @param Submerchant $row
     * @return void
     * @throws PayuOnboardingRequestException
     */
    private function assertEarlierCreateHasSettled(Submerchant $row): void
    {
        if ($row->getPayuEmail() === null) {
            return;
        }
        $lastAttempt = strtotime((string)$row->getData('last_attempt_at') . ' UTC');
        if ($lastAttempt !== false
            && $lastAttempt <= $this->dateTime->gmtTimestamp() - Submerchant::CREATE_SETTLE_SECONDS
        ) {
            return;
        }
        throw new PayuOnboardingRequestException(
            'An earlier create for this vendor went unanswered and may not show in PayU\'s listing yet; '
            . 'not creating again until an hour after it.'
        );
    }

    /**
     * Commit-before-call: record that a call is about to be made, so a crash mid-call still
     * leaves a trace of when.
     *
     * @param Submerchant $row
     * @return void
     */
    private function markAttempt(Submerchant $row): void
    {
        $row->setData('last_attempt_at', $this->dateTime->gmtDate('Y-m-d H:i:s'));
        $this->repository->save($row);
    }
}
