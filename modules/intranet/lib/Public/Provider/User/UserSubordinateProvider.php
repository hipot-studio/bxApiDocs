<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;
use Bitrix\Intranet\Internal\Integration\Humanresources\SubordinateService;
use Bitrix\Main\ArgumentException;

class UserSubordinateProvider
{
	public function __construct(
		private ?SubordinateService $service = null,
	)
	{
		$this->service = $service ?? new SubordinateService();
	}

	public function getDepartments(int $userId): ?array
	{
		if ($userId <= 0)
		{
			throw new ArgumentException('User ID must be positive', 'userId');
		}

		return $this->service->getDepartments($userId);
	}

	public function getSubordinates(int $userId, int $departmentId, int $lastUserId = 0, int $limit = 20): ?array
	{
		if ($userId <= 0)
		{
			throw new ArgumentException('User ID must be positive', 'userId');
		}
		if ($departmentId <= 0)
		{
			throw new ArgumentException('Department ID must be positive', 'departmentId');
		}
		if ($lastUserId < 0)
		{
			throw new ArgumentException('Last user ID must be nonnegative', 'lastUserId');
		}
		if ($limit < 1 || $limit > 100)
		{
			throw new ArgumentException('Limit must be between 1 and 100', 'limit');
		}

		$page = $this->service->getPage($userId, $departmentId, $lastUserId, $limit);
		if ($page === null)
		{
			return null;
		}

		foreach ($page['subordinateUsers'] as $index => $user)
		{
			$page['subordinateUsers'][$index] = $user->toArray();
		}

		return $page;
	}

	public function getFirstForPreview(int $userId): ?BaseInfo
	{
		return $this->service->getFirst($userId);
	}

	public function getCount(int $userId): int
	{
		return $this->service->getCount($userId);
	}
}
