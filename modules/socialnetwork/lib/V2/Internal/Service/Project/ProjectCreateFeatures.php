<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

class ProjectCreateFeatures
{
	public function __construct(
		private readonly LegacyProjectFeaturePolicy $legacyProjectFeaturePolicy,
	)
	{
	}

	public function getFeatures(): array
	{
		return [
			...ProjectDefaultFeatures::getFeatures(),
			...$this->legacyProjectFeaturePolicy->getDisabledFeatures(),
		];
	}
}
