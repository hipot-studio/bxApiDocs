<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\Main\Result;

/**
 * Whether BI Builder answers the caller at all. BuilderQuotaGuard asks it between the quota of the
 * caller and the quota of the portal, so a caller BI is not available to spends only the quota of their
 * own. The check itself belongs to the entry point; the quota knows only its answer.
 */
interface BuilderAvailabilityCheck
{
	/**
	 * @return Result A refusal carries the FailureCode of its reason as the error code.
	 */
	public function check(int $userId): Result;
}
