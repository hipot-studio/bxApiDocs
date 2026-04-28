<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;
use Bitrix\Intranet\Internal\Integration\Humanresources\SubordinateRepository;
use Bitrix\Main\Entity\EntityCollection;

class UserSubordinateProvider
{
	public function __construct(
		private ?SubordinateRepository $repository = null,
	)
	{
		$this->repository = $repository ?? new SubordinateRepository();
	}

	public function getFirstForPreview(int $userId): ?BaseInfo
	{
		return $this->repository->getFirst($userId);
	}

	public function getSubordinates(int $userId): ?EntityCollection
	{
		return $this->repository->getAll($userId);
	}

	public function getCount(int $userId): int
	{
		return $this->repository->getCount($userId);
	}
}