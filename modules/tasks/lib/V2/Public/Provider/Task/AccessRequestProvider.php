<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Task;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;
use Bitrix\Tasks\V2\Internal\Repository\TaskAccessRequestRepositoryInterface;

class AccessRequestProvider
{
	private readonly TaskAccessRequestRepositoryInterface $accessRequestRepository;

	public function __construct()
	{
		$this->accessRequestRepository = Container::getInstance()->get(TaskAccessRequestRepositoryInterface::class);
	}

	public function get(int $userId, int $taskId): AccessRequest
	{
		return $this->accessRequestRepository->get(
			userId: $userId,
			taskId: $taskId,
		);
	}

	public function isExist(int $userId, int $taskId): bool
	{
		return $this->accessRequestRepository->isExists(
			userId: $userId,
			taskId: $taskId,
		);
	}
}
