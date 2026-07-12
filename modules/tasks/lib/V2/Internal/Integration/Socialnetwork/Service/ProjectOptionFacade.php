<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectOptionProvider;

class ProjectOptionFacade
{
	private ?ProjectOptionProvider $projectOptionProvider = null;

	public function __construct(
		private readonly FeatureService $featureService,
	)
	{
	}

	public function isCollabConverted(int $collabId): bool
	{
		if (!$this->featureService->isNewProjectsOn())
		{
			return false;
		}

		return $this->getProvider()->isCollabConverted($collabId);
	}

	public function isCollabConvertedBatch(array $collabIds): array
	{
		if (!$this->featureService->isNewProjectsOn())
		{
			return [];
		}

		return $this->getProvider()->isCollabConvertedBatch($collabIds);
	}

	private function getProvider(): ProjectOptionProvider
	{
		return $this->projectOptionProvider ??= ServiceLocator::getInstance()->get(ProjectOptionProvider::class);
	}
}
