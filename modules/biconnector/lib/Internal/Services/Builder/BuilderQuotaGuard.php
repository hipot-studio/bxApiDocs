<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\Main\Result;

/**
 * Request quota of the builder REST methods.
 *
 * A refusal carries FailureCode::RateLimit as the error code, so the entry point answers it like any
 * refused operation, and never FailureCode::Unavailable: in REST that one tells the client the report is
 * unavailable. The data of a refusal goes out with the answer, and the client repeats the call by its
 * retryAfter, the whole seconds until the quota lets a call pass. A granted quota is handed back to
 * release() once the call ends, whatever the outcome; release() never throws.
 *
 * The quota of the caller is taken before the quota of the portal, and between them the guard asks
 * $availability: its refusal is returned as it is, with the code of its reason and not RateLimit, and
 * holds no slot, while the units of the caller stay spent.
 */
interface BuilderQuotaGuard
{
	public function acquire(
		int $userId,
		string $callerKey,
		BuilderQuotaClass $class,
		BuilderAvailabilityCheck $availability,
	): Result;

	public function release(Result $acquired): void;
}
