<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;
use Bitrix\Tasks\V2\Internal\Service\State\StateFlagsService;

class StateFlagsProvider
{
	public function __construct(
		private readonly StateFlagsService $stateFlagsService,
	)
	{
	}

	public function getMatchesWorkTime(int $userId): bool
	{
		return $this->stateFlagsService
			->get(OptionDictionary::StateFlags, $userId)
			->matchesWorkTime
			?? true
		;
	}
}
