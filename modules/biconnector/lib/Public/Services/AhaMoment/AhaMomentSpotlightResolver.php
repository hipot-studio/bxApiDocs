<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Services\AhaMoment;

use Bitrix\Main\UI\Spotlight;

final class AhaMomentSpotlightResolver
{
	public function resolve(AhaMomentSpotlightOptions $options): AhaMomentSpotlightConfig
	{
		if ($options->getBaseId() === '' || $options->getMaxShows() < 1)
		{
			return new AhaMomentSpotlightConfig(false);
		}

		$showDelaySeconds = max(0, $options->getShowDelaySeconds());

		for ($showIndex = 1; $showIndex <= $options->getMaxShows(); $showIndex++)
		{
			$spotlight = $this->createSpotlight(
				id: $this->buildSpotlightId($options->getBaseId(), $showIndex),
				userType: $options->getUserType(),
				lifetime: $options->getLifetime(),
			);

			if ($spotlight->isAvailable($options->getUserId()))
			{
				return new AhaMomentSpotlightConfig(
					canShow: true,
					spotlightId: $spotlight->getId(),
					showIndex: $showIndex,
					showDelaySeconds: $showDelaySeconds,
				);
			}
		}

		return new AhaMomentSpotlightConfig(false);
	}

	private function createSpotlight(string $id, string $userType, int $lifetime): Spotlight
	{
		$spotlight = new Spotlight($id);
		$spotlight->setUserType($userType);
		$spotlight->setLifetime($lifetime);

		return $spotlight;
	}

	private function buildSpotlightId(string $baseId, int $showIndex): string
	{
		return sprintf('%s__%d', $baseId, $showIndex);
	}
}
