<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Task\View;

use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\View\ViewedUserCollection;
use Bitrix\Tasks\V2\Internal\Repository\ViewedUserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Task\View\ViewedUserParams;

class ViewedUserProvider
{
	private readonly ViewedUserRepositoryInterface $viewedUserRepository;
	private readonly TaskRightService $taskRightService;

	public function __construct()
	{
		$this->viewedUserRepository = Container::getInstance()->get(ViewedUserRepositoryInterface::class);
		$this->taskRightService = Container::getInstance()->get(TaskRightService::class);
	}

	public function tail(ViewedUserParams $viewedUserParams): ViewedUserCollection
	{
		if (!$this->checkAccess($viewedUserParams))
		{
			return new ViewedUserCollection();
		}

		return $this->viewedUserRepository->tail(
			taskId: $viewedUserParams->taskId,
			offset: (int)$viewedUserParams->pager?->getOffset(),
			limit: (int)$viewedUserParams->pager?->getLimit(),
		);
	}

	public function getCount(ViewedUserParams $viewedUserParams): int
	{
		if (!$this->checkAccess($viewedUserParams))
		{
			return 0;
		}

		return $this->viewedUserRepository->getCount($viewedUserParams->taskId);
	}

	protected function checkAccess(ViewedUserParams $viewedUserParams): bool
	{
		return
			!$viewedUserParams->checkAccess
			|| $this->taskRightService->canView($viewedUserParams->userId, $viewedUserParams->taskId);
	}
}
