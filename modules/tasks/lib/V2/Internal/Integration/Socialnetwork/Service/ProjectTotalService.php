<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Provider\Counter\ProjectTotalProvider;
use Bitrix\Tasks\Internals\Counter;

class ProjectTotalService
{
	private ?ProjectTotalProvider $projectTotalProvider;

	public function __construct(
		private readonly FeatureService $featureService,
	)
	{
		$this->projectTotalProvider = null;

		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		if (class_exists(ProjectTotalProvider::class))
		{
			$this->projectTotalProvider = Container::getInstance()->get(ProjectTotalProvider::class);
		}
	}

	public function getTotal(int $userId): int
	{
		if (!$this->projectTotalProvider || !$this->featureService->isNewProjectsOn())
		{
			return $this->getLegacyValue($userId);
		}

		return $this->projectTotalProvider->getTotal($userId);
	}

	private function getLegacyValue(int $userId): int
	{
		$counter = Counter::getInstance($userId);

		return $counter->get(Counter\CounterDictionary::COUNTER_SONET_TOTAL_EXPIRED)
			+ $counter->get(Counter\CounterDictionary::COUNTER_SONET_TOTAL_COMMENTS)
		;
	}
}
