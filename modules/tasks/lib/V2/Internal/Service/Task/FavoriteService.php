<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\V2\Internal\Service\Task\Favorite\Action\NotifyLivefeed;
use Bitrix\Tasks\V2\Internal\Service\Task\Favorite\Action\RunToggleEvent;
use Bitrix\Tasks\V2\Internal\Repository\FavoriteTaskRepositoryInterface;

class FavoriteService
{
	public function __construct(
		private readonly FavoriteTaskRepositoryInterface $favoriteTaskRepository,
	)
	{

	}

	public function add(int $taskId, int $userId): void
	{
		$this->favoriteTaskRepository->add(
			taskId: $taskId,
			userId: $userId,
		);

		(new RunToggleEvent())(
			taskId: $taskId,
			userId: $userId,
			isFavorite: false
		);

		(new NotifyLivefeed())(
			taskId: $taskId,
			userId: $userId,
			isFavorite: false,
		);
	}

	public function delete(int $taskId, int $userId): void
	{
		$this->favoriteTaskRepository->delete(
			taskId: $taskId,
			userId: $userId,
		);

		(new RunToggleEvent())(
			taskId: $taskId,
			userId: $userId,
			isFavorite: false
		);

		(new NotifyLivefeed())(
			taskId: $taskId,
			userId: $userId,
			isFavorite: false,
		);
	}
}