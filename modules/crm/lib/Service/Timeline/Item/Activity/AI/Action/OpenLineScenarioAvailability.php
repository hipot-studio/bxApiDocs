<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action;

final class OpenLineScenarioAvailability
{
	private function __construct() {}

	public static function isDisabled(
		bool $isInitialRunAvailable,
		bool $isRepeatRunAvailable,
		bool $isSuccess,
		bool $isPending,
		bool $isErrorsLimitExceeded,
	): bool
	{
		if ($isErrorsLimitExceeded || $isPending)
		{
			return true;
		}

		if ($isSuccess)
		{
			return !$isRepeatRunAvailable;
		}

		return !$isInitialRunAvailable;
	}
}
