<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;

class ProjectOptionService
{
	public function __construct(
		private readonly CollabOptionRepository $collabOptionRepository,
	)
	{
	}

	public function isCollabConverted(int $collabId): bool
	{
		if ($collabId <= 0)
		{
			return false;
		}

		$options = $this->collabOptionRepository->getRawOptions(
			$collabId,
			[ConvertProgressMapper::CONVERT_STATUS],
		);

		$status = ConvertStatus::tryFrom($options[ConvertProgressMapper::CONVERT_STATUS] ?? '');

		return $status?->isConverted() ?? false;
	}

	public function isCollabConvertedBatch(array $collabIds): array
	{
		if ($collabIds === [])
		{
			return [];
		}

		$statuses = $this->collabOptionRepository->getOptionValueBatch(
			$collabIds,
			ConvertProgressMapper::CONVERT_STATUS,
		);

		$result = [];
		foreach ($collabIds as $collabId)
		{
			$status = ConvertStatus::tryFrom($statuses[$collabId] ?? '');

			$result[$collabId] = $status?->isConverted() ?? false;
		}

		return $result;
	}
}
