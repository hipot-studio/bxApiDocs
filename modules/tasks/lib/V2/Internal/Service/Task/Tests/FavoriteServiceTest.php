<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Tests;

use Bitrix\Tasks\V2\Internal\Repository\FavoriteTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\EventService;
use Bitrix\Tasks\V2\Internal\Service\Task\FavoriteService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class FavoriteServiceTest extends TestCase
{
	private MockObject $favoriteTaskRepository;
	private MockObject $eventService;
	private MockObject $favoriteService;

	protected function setUp(): void
	{
		$this->favoriteTaskRepository = $this->createMock(FavoriteTaskRepositoryInterface::class);
		$this->eventService = $this->createMock(EventService::class);

		$this->favoriteService = $this->getMockBuilder(FavoriteService::class)
			->setConstructorArgs([
				'favoriteTaskRepository' => $this->favoriteTaskRepository,
				'eventService' => $this->eventService,
			])
			->onlyMethods(['notifyLiveFeed'])
			->getMock();
	}

	public function testAdd(): void
	{
		$taskId = 1;
		$userId = 2;

		$this->favoriteTaskRepository->expects($this->once())
			->method('add')
			->with($taskId, $userId);

		$this->eventService->expects($this->once())
			->method('send')
			->with('OnTaskToggleFavorite', [
				'taskId' => $taskId,
				'userId' => $userId,
				'isFavorite' => true,
			]);

		$this->favoriteService->expects($this->once())
			->method('notifyLiveFeed')
			->with(
				taskId: $taskId,
				userId: $userId,
				isFavorite: true
			);

		$this->favoriteService->add($taskId, $userId);
	}

	public function testDelete(): void
	{
		$taskId = 3;
		$userId = 4;

		$this->favoriteTaskRepository->expects($this->once())
			->method('delete')
			->with($taskId, $userId);

		$this->eventService->expects($this->once())
			->method('send')
			->with('OnTaskToggleFavorite', [
				'taskId' => $taskId,
				'userId' => $userId,
				'isFavorite' => false,
			]);

		$this->favoriteService->expects($this->once())
			->method('notifyLiveFeed')
			->with(
				taskId: $taskId,
				userId: $userId,
				isFavorite: false
			);

		$this->favoriteService->delete($taskId, $userId);
	}
}

