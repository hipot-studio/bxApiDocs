<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Rest\V3\Exception\FeatureNotAvailableOnCurrentPlanException;

/**
 * BI Builder is switched off on the portal plan: the framework refusal of the plan, answered with the builder
 * failure code. A repeat does not help, unlike a dashboard that is unavailable for a while.
 */
class BuilderFeatureNotAvailableException extends FeatureNotAvailableOnCurrentPlanException implements BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::FeatureDisabled;
	}

	protected function getClassWithPhrase(): string
	{
		return FeatureNotAvailableOnCurrentPlanException::class;
	}
}
