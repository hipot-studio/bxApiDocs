<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Support;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\RestAvailability;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderAvailabilityCheck;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

/**
 * The availability of BI Builder to a REST caller, the one place it is decided for the builder methods.
 * The quota of the entry point asks it between its two steps, so no builder action is reached without it,
 * except one marked #[DescribesApi].
 *
 * A builder switched off on the tariff is told apart first: the operation would report it as a temporarily
 * unavailable dashboard, and a repeat would not help. Then a caller without a user is refused, then
 * RestAvailability decides. The refusals carry FEATURE_DISABLED, ACCESS_DENIED and BI_UNAVAILABLE.
 */
final class BuilderRestAvailabilityCheck implements BuilderAvailabilityCheck
{
	public function check(int $userId): Result
	{
		if (!Feature::isBuilderEnabled())
		{
			return Refusal::error(FailureCode::FeatureDisabled, 'BI Builder is not available on this tariff.');
		}

		if ($userId <= 0)
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied.');
		}

		return ServiceLocator::getInstance()
			->get(RestAvailability::class)
			->check(new Actor($userId, Channel::Rest))
		;
	}
}
