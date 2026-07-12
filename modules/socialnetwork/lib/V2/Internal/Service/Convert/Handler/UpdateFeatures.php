<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Property\Feature;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Provider\FeatureProvider;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityType;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectConvertFeatureUpdateException;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use CSocNetFeatures;

class UpdateFeatures implements HandlerInterface
{
	private const REQUIRED_FEATURES = [
		FeatureDictionary::Tasks->value,
		FeatureDictionary::Calendar->value,
		FeatureDictionary::Files->value,
		FeatureDictionary::Chat->value,
		FeatureDictionary::Marketplace->value,
		FeatureDictionary::Blog->value,
	];

	/**
	 * @throws ProjectConvertFeatureUpdateException
	 */
	public function __invoke(Workgroup $group): void
	{
		$groupId = $group->getId();

		if ($groupId <= 0)
		{
			throw new ProjectConvertFeatureUpdateException(
				sprintf('Group id is invalid: [%s]', $groupId)
			);
		}

		$features = $this->getFeaturesToActivate($groupId);

		foreach ($features as $featureName)
		{
			$featureId = $this->setFeature($groupId, $featureName, true);

			if ($featureId === false)
			{
				throw new ProjectConvertFeatureUpdateException(
					sprintf('Unable to set feature %s', $featureName)
				);
			}
		}
	}

	/**
	 * @return array<string> Feature names
	 */
	protected function getFeaturesToActivate(int $groupId): array
	{
		$activeFeatureNames = array_flip($this->resolveActiveFeatureNames($groupId));

		$featuresToActivate = [];
		foreach (self::REQUIRED_FEATURES as $featureName)
		{
			if (!isset($activeFeatureNames[$featureName]))
			{
				$featuresToActivate[$featureName] = $featureName;
			}
		}

		return array_values($featuresToActivate);
	}

	/**
	 * @return string[] Names of currently active features for the group
	 */
	protected function resolveActiveFeatureNames(int $groupId): array
	{
		$currentFeatures = $this->getCurrentFeatures($groupId);

		$activeFeatureNames = [];
		foreach ($currentFeatures as $feature)
		{
			if ($feature->isActive)
			{
				// prevent duplicates
				$activeFeatureNames[$feature->feature] = $feature->feature;
			}
		}

		return array_values($activeFeatureNames);
	}

	/** @return Feature[] */
	protected function getCurrentFeatures(int $groupId): array
	{
		return FeatureProvider::getInstance()->getFeatures($groupId);
	}

	protected function setFeature(int $groupId, string $featureName, bool $isActive): int|bool
	{
		return CSocNetFeatures::setFeature(
			EntityType::Group->value,
			$groupId,
			$featureName,
			$isActive,
			false,
			['isCollab' => true]
		);
	}
}
