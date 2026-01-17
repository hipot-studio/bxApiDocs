<?php

namespace Bitrix\TasksMobile\Infrastructure\Controller\Task;

use Bitrix\Disk\Driver;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Tasks\V2\Infrastructure\Controller\Task\Result as TaskResultController;
use Bitrix\Tasks\V2\Infrastructure\Controller\Task\Result\Message;
use Bitrix\TasksMobile\Internal\Dto\Task\ResultDto;

class Result extends JsonController
{
	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.tail
	 *
	 * @param int $taskId
	 * @param bool $withMap
	 * @param PageNavigation|null $pageNavigation
	 * @return array|null
	 */
	#[CloseSession]
	public function tailAction(int $taskId, bool $withMap = true, ?PageNavigation $pageNavigation = null): ?array
	{
		$taskControllerResponse = $this->forward(
			TaskResultController::class,
			'tail',
			[
				'taskId' => $taskId,
				'withMap' => $withMap,
				'pageNavigation' => $pageNavigation,
			],
		);

		return $this->prepareResultsResponse($taskControllerResponse);
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.getAll
	 *
	 * @param int $taskId
	 * @param bool $withMap
	 * @return array|null
	 */
	#[CloseSession]
	public function getAllAction(int $taskId, bool $withMap = true): ?array
	{
		$taskControllerResponse = $this->forward(
			TaskResultController::class,
			'getAll',
			[
				'taskId' => $taskId,
				'withMap' => $withMap,
			],
		);

		return $this->prepareResultsResponse($taskControllerResponse);
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.get
	 *
	 * @param int $resultId
	 * @return array|null
	 */
	#[CloseSession]
	public function getAction(int $resultId): ?array
	{
		$taskControllerResponse = $this->forward(
			TaskResultController::class,
			'get',
			[
				'resultId' => $resultId,
			],
		);
		$result = ($taskControllerResponse?->toArray() ?? null);

		if (!is_array($result))
		{
			return null;
		}

		return [
			'items' => $this->prepareItems([$result]),
			'users' => $this->getUsersData([$result]),
		];
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.addFromMessage
	 *
	 * @param int $messageId
	 * @return array|null
	 */
	public function addFromMessageAction(int $messageId): ?array
	{
		$taskControllerResponse = $this->forward(
			Message::class,
			'add',
			[
				'messageId' => $messageId,
			],
		);
		$result = ($taskControllerResponse?->toArray() ?? null);

		if (!is_array($result))
		{
			return null;
		}

		return [
			'items' => $this->prepareItems([$result]),
		];
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.add
	 *
	 * @param array $result
	 * @return array|null
	 */
	public function addAction(array $result): ?array
	{
		$taskControllerResponse = $this->forward(
			TaskResultController::class,
			'add',
			[
				'results' => [$result],
			],
		);
		$results = ($taskControllerResponse?->toArray() ?? null);

		if (!is_array($results))
		{
			return null;
		}

		return [
			'items' => $this->prepareItems($results),
		];
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.update
	 *
	 * @param array $result
	 * @return array|null
	 */
	public function updateAction(array $result): ?array
	{
		$taskControllerResponse = $this->forward(
			TaskResultController::class,
			'update',
			[
				'result' => $result,
			],
		);
		$result = ($taskControllerResponse?->toArray() ?? null);

		if (!is_array($result))
		{
			return null;
		}

		return [
			'items' => $this->prepareItems([$result]),
		];
	}

	/**
	 * @ajaxAction tasksmobile.v2.Task.Result.delete
	 *
	 * @param int $resultId
	 * @return bool|null
	 */
	public function deleteAction(int $resultId): ?bool
	{
		return $this->forward(
			TaskResultController::class,
			'delete',
			[
				'resultId' => $resultId,
			],
		);
	}

	private function prepareResultsResponse(?array $taskControllerResponse): ?array
	{
		if (!is_array($taskControllerResponse))
		{
			return null;
		}

		$results = ($taskControllerResponse['results'] ?? null);
		if (!is_null($results))
		{
			$results = $results->toArray();
		}

		if (!is_array($results))
		{
			return null;
		}

		$actionResult = [
			'items' => $this->prepareItems($results),
			'users' => $this->getUsersData($results),
		];

		if (is_array($taskControllerResponse['map'] ?? null))
		{
			$actionResult['map'] = $taskControllerResponse['map'];
		}

		return $actionResult;
	}

	private function prepareItems(array $results): array
	{
		$items = [];
		$urlManager = Driver::getInstance()->getUrlManager();

		foreach ($results as $result)
		{
			$files = [];

			foreach ($result['files'] as $file)
			{
				$fileId = $file['id'];
				$files[] = [
					'ID' => $fileId,
					'OBJECT_ID' => $file['customData']['objectId'],
					'NAME' => $file['name'],
					'TYPE' => $file['type'],
					'URL' => $urlManager::getUrlUfController('show', ['attachedId' => $fileId]),
					'PREVIEW_URL' => $file['serverPreviewUrl'],
					'WIDTH' => $file['width'],
					'HEIGHT' => $file['height'],
				];
			}

			$items[] = ResultDto::make([
				'ID' => $result['id'],
				'TASK_ID' => $result['taskId'],
				'MESSAGE_ID' => $result['messageId'],
				'AUTHOR_ID' => $result['author']['id'],
				'CREATED_AT_TS' => $result['createdAtTs'],
				'STATUS' => $result['status'],
				'TEXT' => Emoji::decode($result['text']),
				'FILES' => $files,
			]);
		}

		return $items;
	}

	private function getUsersData(array $results): array
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();
		$userIds = [];

		foreach ($results as $result)
		{
			$userIds[] = (int)$result['author']['id'];
		}
		$userIds = array_filter($userIds, fn ($userId) => ($userId !== $currentUserId));

		return UserRepository::getByIds($userIds);
	}
}
