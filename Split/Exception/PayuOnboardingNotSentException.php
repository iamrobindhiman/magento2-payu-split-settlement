<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

/**
 * The request never left: we could not get a bearer token for it (a refused credential, a rate
 * limit or outage at the token endpoint, a transport failure while asking for one).
 *
 * The difference from its parent matters for a create. "Failed at transport level" on the create
 * itself is ambiguous — PayU may have made the account — so the create-sent mark (payu_email) must
 * stay. "Never sent" is certain, so the mark goes back to what it was before this attempt; left set,
 * it would make an empty listing untrustworthy and strand the vendor (wave-4 integrity F1).
 */
class PayuOnboardingNotSentException extends PayuOnboardingRequestException
{
}
