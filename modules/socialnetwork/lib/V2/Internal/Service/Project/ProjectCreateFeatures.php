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

	/**
	 * Feature states a project is created with. Of what the request asks for, only the tools that are merely off
	 * by default and available on this portal may be chosen: everything a Project 2.0 may never turn on, and
	 * everything the portal does not offer, keeps its state whatever was sent.
	 *
	 * @param array<string, mixed>|null $requestedFeatures
	 * @return array<string, bool>
	 */
	public function getFeatures(?array $requestedFeatures = null): array
	{
		return [
			...ProjectDefaultFeatures::getFeatures(),
			...$this->legacyProjectFeaturePolicy->getDisabledFeatures(),
			...$this->filterRequestedFeatures($requestedFeatures),
		];
	}

	/**
	 * @param array<string, mixed>|null $requestedFeatures
	 * @return array<string, bool>
	 */
	private function filterRequestedFeatures(?array $requestedFeatures): array
	{
		if ($requestedFeatures === null)
		{
			return [];
		}

		$allowedFeatureIds = array_flip($this->legacyProjectFeaturePolicy->getAvailableInactiveOnCreateFeatureIds());
		$result = [];

		foreach ($requestedFeatures as $featureId => $isActive)
		{
			if (!is_string($featureId) || !isset($allowedFeatureIds[$featureId]) || !is_bool($isActive))
			{
				continue;
			}

			$result[$featureId] = $isActive;
		}

		return $result;
	}
}
