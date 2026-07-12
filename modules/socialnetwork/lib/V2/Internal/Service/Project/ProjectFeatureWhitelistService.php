<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Provider\FeatureProvider;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityType;
use CSocNetFeatures;

class ProjectFeatureWhitelistService
{
	public function __construct(
		private readonly FeatureProvider $featureProvider,
		private readonly ProjectCreateFeatures $projectCreateFeatures,
	)
	{
	}

	public function syncWithCreateDefaults(int $projectId): void
	{
		$currentFeatures = $this->featureProvider->getFeatures($projectId);
		$featuresToSync = $this->projectCreateFeatures->getFeatures();

		foreach ($featuresToSync as $featureId => $isActive)
		{
			$currentFeature = $currentFeatures[$featureId] ?? null;
			if ($currentFeature !== null && $currentFeature->isActive === $isActive)
			{
				continue;
			}

			CSocNetFeatures::setFeature(
				EntityType::Group->value,
				$projectId,
				$featureId,
				$isActive,
				false,
				['isCollab' => true],
			);
		}
	}
}
