<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\FeatureTable;
use CSocNetAllowed;
use CSocNetFeatures;

class ProjectFeatureRepository
{
	public function getStoredFeatureNames(int $projectId): array
	{
		return CSocNetFeatures::getFeaturesNames(\SONET_ENTITY_GROUP, $projectId);
	}

	public function hasFeature(int $projectId, string $featureId): bool
	{
		return $featureId !== '' && array_key_exists($featureId, $this->getStoredFeatureNames($projectId));
	}

	public function getBaseFeatureId(int $projectId): ?string
	{
		$feature = FeatureTable::query()
			->setSelect(['FEATURE'])
			->where('ENTITY_ID', $projectId)
			->where('ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->where('BASE', 'Y')
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		$featureId = $feature?->getFeature();

		return is_string($featureId) && $featureId !== '' ? $featureId : null;
	}

	public function saveBaseFeatureId(int $projectId, string $featureId): bool
	{
		$featureCollection = FeatureTable::query()
			->setSelect(['ID', 'FEATURE', 'BASE'])
			->where('ENTITY_ID', $projectId)
			->where('ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->exec()
			->fetchCollection()
		;

		$hasBaseFeature = false;
		foreach ($featureCollection as $feature)
		{
			if ($feature->getFeature() === $featureId)
			{
				$hasBaseFeature = true;
				break;
			}
		}

		if (!$hasBaseFeature)
		{
			return false;
		}

		foreach ($featureCollection as $feature)
		{
			$isBase = $feature->getFeature() === $featureId;
			$baseValue = $isBase ? 'Y' : 'N';

			if ($feature->getBase() === $baseValue)
			{
				continue;
			}

			$result = CSocNetFeatures::update(
				(int)$feature->getId(),
				[
					'BASE' => $baseValue,
					'=DATE_UPDATE' => \CDatabase::CurrentTimeFunction(),
				],
			);

			if ($result === false)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string[]
	 */
	public function getPersistedFeatureIds(int $projectId): array
	{
		return array_keys($this->getFeatureStates($projectId));
	}

	/**
	 * @return array<string, bool>
	 */
	public function getFeatureStates(int $projectId): array
	{
		$result = $this->loadExplicitFeatureStates($projectId);

		foreach ($this->getStoredFeatureNames($projectId) as $featureName => $_)
		{
			if (!is_string($featureName) || $featureName === '' || array_key_exists($featureName, $result))
			{
				continue;
			}

			$result[$featureName] = true;
		}

		return $result;
	}

	/**
	 * @return array<string, bool>
	 */
	public function getGroupFeatureStates(int $projectId): array
	{
		$explicitFeatureStates = $this->loadExplicitFeatureStates($projectId);
		$result = [];

		foreach ($this->getAllowedFeatureIds() as $featureId)
		{
			if (!is_string($featureId) || $featureId === '')
			{
				continue;
			}

			$result[$featureId] = $explicitFeatureStates[$featureId] ?? true;
		}

		return $result;
	}

	public function getAllowedFeatureIds(): array
	{
		return $this->loadAllowedFeatureIds();
	}

	/**
	 * @return array<string, bool>
	 */
	protected function loadExplicitFeatureStates(int $projectId): array
	{
		$featureCollection = FeatureTable::query()
			->setSelect(['FEATURE', 'ACTIVE'])
			->where('ENTITY_ID', $projectId)
			->where('ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->exec()
			->fetchCollection()
		;

		$result = [];
		foreach ($featureCollection as $feature)
		{
			$featureName = $feature->getFeature();
			if (!is_string($featureName) || $featureName === '')
			{
				continue;
			}

			$result[$featureName] = $feature->getActive() === 'Y';
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	protected function loadAllowedFeatureIds(): array
	{
		$result = [];
		foreach (CSocNetAllowed::getAllowedFeatures() as $featureId => $featureSettings)
		{
			if (
				!is_string($featureId)
				|| $featureId === ''
				|| !is_array($featureSettings)
				|| !in_array(\SONET_ENTITY_GROUP, $featureSettings['allowed'] ?? [], true)
			)
			{
				continue;
			}

			$result[] = $featureId;
		}

		return $result;
	}
}
