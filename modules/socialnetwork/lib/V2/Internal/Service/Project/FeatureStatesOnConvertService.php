<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;
use Throwable;

class FeatureStatesOnConvertService
{
	public const OPTION_NAME = 'FEATURE_STATES_ON_CONVERT';

	public function __construct(
		private readonly CollabOptionRepository $collabOptionRepository,
		private readonly ProjectFeatureRepository $projectFeatureRepository,
		private readonly ConvertProgressRepositoryInterface $convertProgressRepository,
	)
	{
	}

	/**
	 * @return array<string, bool>
	 */
	public function getOrCreateForConvertedProject(int $projectId): array
	{
		$storedStates = $this->getStoredStates($projectId);
		if ($storedStates !== [])
		{
			return $storedStates;
		}

		if (!$this->isConverted($projectId))
		{
			return [];
		}

		return $this->storeCurrentStates($projectId);
	}

	/**
	 * @return array<string, bool>
	 */
	public function getStoredStates(int $projectId): array
	{
		if ($projectId <= 0)
		{
			return [];
		}

		$options = $this->collabOptionRepository->getRawOptions(
			collabId: $projectId,
			optionNames: [self::OPTION_NAME],
		);
		$rawValue = $options[self::OPTION_NAME] ?? null;
		if (!is_string($rawValue) || $rawValue === '')
		{
			return [];
		}

		return $this->decodeStates($rawValue);
	}

	/**
	 * @return array<string, bool>
	 */
	public function storeCurrentStates(int $projectId): array
	{
		if ($projectId <= 0)
		{
			return [];
		}

		$states = $this->projectFeatureRepository->getGroupFeatureStates($projectId);

		$this->collabOptionRepository->setOption(
			collabId: $projectId,
			optionName: self::OPTION_NAME,
			value: $this->encodeStates($states),
		);

		return $states;
	}

	/**
	 * @param array<string, bool> $states
	 */
	private function encodeStates(array $states): string
	{
		$normalizedStates = [];
		foreach ($states as $featureId => $isActive)
		{
			if (!is_string($featureId) || $featureId === '' || !is_bool($isActive))
			{
				continue;
			}

			$normalizedStates[$featureId] = $isActive;
		}

		ksort($normalizedStates);

		return Json::encode($normalizedStates);
	}

	/**
	 * @return array<string, bool>
	 */
	private function decodeStates(string $rawValue): array
	{
		try
		{
			$decoded = Json::decode($rawValue);
		}
		catch (Throwable)
		{
			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		$result = [];
		foreach ($decoded as $featureId => $isActive)
		{
			if (!is_string($featureId) || $featureId === '' || !is_bool($isActive))
			{
				continue;
			}

			$result[$featureId] = $isActive;
		}

		return $result;
	}

	private function isConverted(int $projectId): bool
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
}
