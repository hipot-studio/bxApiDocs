<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\V2\Internal\Access\Task;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\DeleteResultCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\RequireResultCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\UpdateResultCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Provider\TaskResultProvider;

class Result extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Result.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Result $result,
		TaskResultProvider $taskResultProvider,
	): ?Entity\Result
	{
		return $taskResultProvider->getResultById($result->id, $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.getMap
	 */
	#[CloseSession]
	public function getMapAction(
		#[Task\Permission\Read]
		Entity\Task $task,
		TaskResultProvider $taskResultProvider,
	): array
	{
		return $taskResultProvider->getResultMessageMap((int)$task->getId());
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.require
	 */
	#[CloseSession]
	public function requireAction(
		#[Task\Permission\Update]
		Entity\Task $task,
	): ?bool
	{
		$commandResult = (new RequireResultCommand(
			taskId: $task->id,
			userId: $this->userId,
			require: $task->requireResult,
			useConsistency: true,
		))->run();

		/** @var \Bitrix\Tasks\V2\Internal\Result\Result $commandResult */
		if (!$commandResult->isSuccess())
		{
			$this->addErrors($commandResult->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.tail
	 */
	#[CloseSession]
	public function tailAction(
		#[Task\Permission\Read]
		Entity\Task $task,
		TaskResultProvider $taskResultProvider,
		PageNavigation $pageNavigation,
		bool $withMap = true,
	): ?array
	{
		$results = $taskResultProvider->getTaskResults(
			taskId: $task->id,
			userId: $this->userId,
			pager: Pager::buildFromPageNavigation($pageNavigation),
		);

		return $this->prepareResultsResponse($results, $taskResultProvider, $task->id, $withMap);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.getAll
	 */
	#[CloseSession]
	public function getAllAction(
		#[Task\Permission\Read]
		Entity\Task $task,
		TaskResultProvider $taskResultProvider,
		bool $withMap = true,
	): array
	{
		$results = $taskResultProvider->getTaskResults(
			taskId: $task->id,
			userId: $this->userId,
		);

		return $this->prepareResultsResponse($results, $taskResultProvider, $task->id, $withMap);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.add
	 */
	public function addAction(
		#[Permission\Add]
		Entity\ResultCollection $results,
		TaskResultProvider $taskResultProvider,
		bool $skipNotification = false,
	): ?Entity\ResultCollection
	{
		$resultIds = [];

		foreach ($results as $result)
		{
			$commandResult = (new AddResultCommand(
				result: $result,
				userId: $this->userId,
				useConsistency: true,
				skipNotification: $skipNotification,
			))->run();

			/** @var \Bitrix\Tasks\V2\Internal\Result\Result $commandResult */
			if (!$commandResult->isSuccess())
			{
				$this->addErrors($commandResult->getErrors());

				continue;
			}

			$resultIds[] = $commandResult->getId();
		}

		return $taskResultProvider->getResults($resultIds, $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.update
	 */
	public function updateAction(
		#[Permission\Update]
		Entity\Result $result,
		TaskResultProvider $taskResultProvider,
	): ?Entity\Result
	{
		$commandResult = (new UpdateResultCommand(
			result: $result,
			userId: $this->userId,
			useConsistency: true,
		))->run();

		if (!$commandResult->isSuccess())
		{
			$this->addErrors($commandResult->getErrors());

			return null;
		}

		return $taskResultProvider->getResultById($result->id, $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Result.delete
	 */
	public function deleteAction(
		#[Permission\Delete]
		Entity\Result $result,
	): ?bool
	{
		$commandResult = (new DeleteResultCommand(
			result: $result,
			userId: $this->userId,
			useConsistency: true,
		))->run();

		if (!$commandResult->isSuccess())
		{
			$this->addErrors($commandResult->getErrors());

			return null;
		}

		return true;
	}

	private function prepareResultsResponse(
		Entity\ResultCollection $results,
		TaskResultProvider $taskResultProvider,
		int $taskId,
		bool $withMap
	): array
	{
		$response = ['results' => $results];

		if ($withMap)
		{
			$response['map'] = $taskResultProvider->getResultMessageMap($taskId);
		}

		return $response;
	}
}
