<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;
use CSocNetFeatures;

class ProjectFeatureToggleService
{
	public function __construct(
		private readonly ConvertProgressRepositoryInterface $convertProgressRepository,
		private readonly ProjectFeatureRepository $projectFeatureRepository,
		private readonly FeatureStatesOnConvertService $featureStatesOnConvertService,
		private readonly LegacyProjectFeaturePolicy $legacyProjectFeaturePolicy,
	)
	{
	}

	public function isAvailable(int $projectId): bool
	{
		if ($projectId <= 0)
		{
			return false;
		}

		return in_array(
			$this->convertProgressRepository->getByGroupId($projectId)->getStatus(),
			[ConvertStatus::CompletedFromGroup, ConvertStatus::CompletedFromCollab],
			true,
		);
	}

	/**
	 * @return string[]
	 */
	public function getToggleableFeatureIds(int $projectId): array
	{
		if (!$this->isAvailable($projectId))
		{
			return [];
		}

		return $this->getPersistedToggleableFeatureIds($projectId);
	}

	/**
	 * @param array<string, mixed>|null $features
	 * @return array<string, bool>
	 */
	public function filterFeaturesForUpdate(int $projectId, ?array $features): array
	{
		if ($features === null || !$this->isAvailable($projectId))
		{
			return [];
		}

		$allowedFeatureIds = array_flip($this->getPersistedToggleableFeatureIds($projectId));
		$result = [];

		foreach ($features as $featureId => $isActive)
		{
			if (!is_string($featureId) || !isset($allowedFeatureIds[$featureId]) || !is_bool($isActive))
			{
				continue;
			}

			$result[$featureId] = $isActive;
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function getPersistedToggleableFeatureIds(int $projectId): array
	{
		$storedStates = $this->featureStatesOnConvertService->getOrCreateForConvertedProject($projectId);
		if ($storedStates === [])
		{
			return [];
		}

		$result = [];
		foreach ($this->legacyProjectFeaturePolicy->getDisabledFeatureIds() as $featureId)
		{
			if (($storedStates[$featureId] ?? false) === true)
			{
				$result[] = $featureId;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, bool> $features
	 */
	public function saveFeatureStates(int $projectId, array $features): Result
	{
		$result = new Result();

		foreach ($features as $featureId => $isActive)
		{
			if ($this->setFeature($projectId, $featureId, $isActive) === false)
			{
				$result->addError(
					new Error(
						sprintf('Failed to update feature "%s"', $featureId),
						'ERROR_NO_FEATURE_ID',
					),
				);
			}
		}

		return $result;
	}

	public function canSetBaseFeature(int $projectId, string $featureId): bool
	{
		if ($this->canCreateBaseFeature($featureId))
		{
			return true;
		}

		return $this->projectFeatureRepository->hasFeature($projectId, $featureId);
	}

	public function saveBaseFeatureId(int $projectId, string $featureId): Result
	{
		$result = new Result();

		if (!Feature::isOldPortalForNewProject())
		{
			return $result;
		}

		if (!$this->ensureBaseFeatureExists($projectId, $featureId))
		{
			return $result->addError(
				new Error(
					sprintf('Failed to update base feature "%s"', $featureId),
					'ERROR_NO_BASE_FEATURE_ID',
				),
			);
		}

		if (!$this->projectFeatureRepository->saveBaseFeatureId($projectId, $featureId))
		{
			$result->addError(
				new Error(
					sprintf('Failed to update base feature "%s"', $featureId),
					'ERROR_NO_BASE_FEATURE_ID',
				),
			);
		}

		return $result;
	}

	private function ensureBaseFeatureExists(int $projectId, string $featureId): bool
	{
		if ($this->projectFeatureRepository->hasFeature($projectId, $featureId))
		{
			return true;
		}

		if (!$this->canCreateBaseFeature($featureId))
		{
			return false;
		}

		return $this->setFeature($projectId, $featureId, true) !== false;
	}

	private function canCreateBaseFeature(string $featureId): bool
	{
		return $featureId === FeatureDictionary::Chat->value || $this->isSpecialBaseFeature($featureId);
	}

	private function isSpecialBaseFeature(string $featureId): bool
	{
		return in_array($featureId, FeatureDictionary::getSpecialFeatureIds(), true);
	}

	protected function setFeature(int $projectId, string $featureId, bool $isActive): int|bool
	{
		return CSocNetFeatures::setFeature(
			EntityType::Group->value,
			$projectId,
			$featureId,
			$isActive,
			false,
			['isCollab' => true],
		);
	}
}
