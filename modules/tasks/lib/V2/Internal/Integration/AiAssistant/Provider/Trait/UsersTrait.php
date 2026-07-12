<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Trait;

use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

trait UsersTrait
{
	private readonly UserRepositoryInterface $userRepository;

	private function appendUserNames(array $rows): array
	{
		$userIds = array_column($rows, 'USER_ID');
		if (empty($userIds))
		{
			return $rows;
		}

		$userIds = array_unique($userIds);

		$authors = $this->userRepository->getNamesByIds($userIds);
		if (empty($authors))
		{
			return $rows;
		}

		foreach ($rows as $key => $row)
		{
			$userId = (int)($row['USER_ID'] ?? 0);
			if ($userId <= 0 || !isset($authors[$userId]))
			{
				continue;
			}

			$rows[$key]['USER_NAME'] = $authors[$userId];
		}

		return $rows;
	}
}
