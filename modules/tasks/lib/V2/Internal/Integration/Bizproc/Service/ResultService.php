<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Bizproc\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Result\Result;
use Bitrix\Tasks\Internals\Task\Result\ResultManager;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskResultMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;

class ResultService
{
	public function __construct(
		private readonly TaskResultRepositoryInterface $resultRepository,
		private readonly TaskResultMapper $resultMapper,
	)
	{
	}

	public function enrichFieldsWithResult(array $fields, int $taskId, array $map): array
	{
		if (FormV2Feature::isOn('automation'))
		{
			return $this->enrichWithMessageResult($fields, $taskId, $map);
		}

		return $this->enrichWithCommentResult($fields, $taskId, $map);
	}

	private function enrichWithMessageResult(array $fields, int $taskId, array $map): array
	{
		$results = null;

		if (isset($map['COMMENT_RESULT']))
		{
			$results = $this->resultRepository->getByTaskId($taskId);

			$fields['COMMENT_RESULT'] = [];
			foreach ($results as $result)
			{
				$fields['COMMENT_RESULT'][] = $result;
			}
		}

		if (isset($map['COMMENT_RESULT_LAST']))
		{
			if ($results === null)
			{
				$lastResult = $this->resultRepository->getLast($taskId);
			}
			else
			{
				$resultIds = $results->getIdList();

				$lastId = !empty($resultIds) ? max($resultIds) : null;

				/** @var ?Result $lastResult */
				$resultObject = $results->getByPrimary($lastId);

				$author = new User($resultObject?->getCreatedBy());

				$lastResult = $resultObject ? $this->resultMapper->mapToEntity($resultObject, $author) : null;
			}

			$fields['COMMENT_RESULT_LAST'] = null;
			if ($lastResult !== null)
			{
				$fields['COMMENT_RESULT_LAST'] = [
					'ID' => $lastResult->id,
					'TASK_ID' => $lastResult->taskId,
					'STATUS' => $lastResult->status,
					'TEXT' => $lastResult->text,
					'CREATED_BY' => $lastResult->author?->id,
					'CREATED_AT' => DateTime::createFromTimestamp($lastResult->createdAtTs),
					'UPDATED_AT' => DateTime::createFromTimestamp($lastResult->updatedAtTs),
				];
			}
		}

		return $fields;
	}

	private function enrichWithCommentResult(array $fields, int $taskId, array $map): array
	{
		if (!Loader::includeModule('forum'))
		{
			return $fields;
		}

		if (isset($map['COMMENT_RESULT']))
		{
			$fields['COMMENT_RESULT'] = (new ResultManager(0))->getTaskResults($taskId);
		}

		if (isset($map['COMMENT_RESULT_LAST']))
		{
			$fields['COMMENT_RESULT_LAST'] = ResultManager::getLastResult($taskId);
		}

		return $fields;
	}
}
