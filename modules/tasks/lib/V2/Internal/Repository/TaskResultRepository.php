<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\DB\SqlException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Result\EO_Result_Collection;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Model\TaskResultMessageTable;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskResultMapper;

class TaskResultRepository implements TaskResultRepositoryInterface
{
	public function __construct(
		private readonly TaskResultMapper $taskResultMapper,
		private readonly UserRepositoryInterface $userRepository,
		private readonly TaskParameterRepositoryInterface $taskParameterRepository,
	)
	{
	}

	public function isResultRequired(int $taskId): bool
	{
		return $this->taskParameterRepository->isResultRequired($taskId);
	}

	public function getById(int $resultId): ?Result
	{
		return $this->getByIds([$resultId])->getFirstEntity();
	}

	public function getByIds(array $resultIds): ResultCollection
	{
		if (empty($resultIds))
		{
			return new ResultCollection();
		}

		$select = [
			'ID',
			'TASK_ID',
			'CREATED_BY',
			'CREATED_AT',
			'UPDATED_AT',
			'TEXT',
			'STATUS',
			'UF_*',
			'MESSAGE_ID' => 'MESSAGE.MESSAGE_ID',
		];

		$results = ResultTable::query()
			->setSelect($select)
			->whereIn('ID', $resultIds)
			->fetchCollection();

		if ($results->isEmpty())
		{
			return new ResultCollection();
		}

		$authorIds = $results->getCreatedByList();
		Collection::normalizeArrayValuesByInt($authorIds, false);

		$authors = $this->userRepository->getByIds($authorIds);

		return $this->taskResultMapper->mapToCollection(
			$results,
			$authors,
		);
	}

	public function getByTask(int $taskId, ?int $limit = null, ?int $offset = null): ResultCollection
	{
		$select = [
			'ID',
			'TASK_ID',
			'CREATED_BY',
			'CREATED_AT',
			'UPDATED_AT',
			'TEXT',
			'STATUS',
			'UF_*',
			'MESSAGE_ID' => 'MESSAGE.MESSAGE_ID',
		];

		$query = ResultTable::query()
			->setSelect($select)
			->where('TASK_ID', $taskId)
			->setOrder(['ID' => 'DESC'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		$results = $query->exec()->fetchCollection();

		$authorIds = $results->getCreatedByList();
		Collection::normalizeArrayValuesByInt($authorIds, false);

		$authors = $this->userRepository->getByIds($authorIds);

		return $this->taskResultMapper->mapToCollection(
			$results,
			$authors,
		);
	}

	public function getAttachmentIdsByResult(int $resultId): ?array
	{
		return $this->getById($resultId)?->fileIds ?? [];
	}

	public function getResultMessageMap(int $taskId): array
	{
		$data = ResultTable::query()
			->setSelect(['ID', 'MESSAGE_ID' => 'MESSAGE.MESSAGE_ID'])
			->where('TASK_ID', $taskId)
			->setOrder(['ID' => 'DESC'])
			->exec()
			->fetchAll()
		;

		$map = [];
		foreach ($data as $item)
		{
			$map[(int)$item['ID']] = $item['MESSAGE_ID'] === null ? null : (int)$item['MESSAGE_ID'];
		}

		return $map;
	}

	public function save(Result $entity, int $userId): int
	{
		$data = [
			'COMMENT_ID' => 0,
			'TASK_ID' => $entity->taskId,
			'TEXT' => $entity->text,
			'CREATED_BY' => $entity->author?->id ?? $userId,
			'CREATED_AT' => $entity->createdAtTs ? DateTime::createFromTimestamp($entity->createdAtTs) : null,
			'UPDATED_AT' => $entity->updatedAtTs ? DateTime::createFromTimestamp($entity->updatedAtTs) : null,
			'STATUS' => $entity->status?->getRaw(),
		];

		if ($entity->id)
		{
			ResultTable::update($entity->id, $data);

			return $entity->id;
		}

		$addResult = ResultTable::add($data);
		if (!$addResult->isSuccess())
		{
			Container::getInstance()->getLogger()->logError($addResult->getError());

			throw new SqlException('Error occurred while adding task result');
		}

		$resultId = $addResult->getId();

		if ($entity->messageId > 0)
		{
			TaskResultMessageTable::addInsertIgnore(['RESULT_ID' => $resultId, 'MESSAGE_ID' => $entity->messageId]);
		}

		return $resultId;
	}

	public function delete(int $id, int $userId): void
	{
		$deleteResult = ResultTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			Container::getInstance()->getLogger()->logError($deleteResult->getError());

			throw new SqlException('Error occurred while deleting task result');
		}

		TaskResultMessageTable::deleteByFilter(['=RESULT_ID' => $id]);
	}

	public function deleteMessageLink(int $messageId): void
	{
		TaskResultMessageTable::deleteByFilter(['=MESSAGE_ID' => $messageId]);
	}

	public function getByTaskId(int $taskId): EO_Result_Collection
	{
		return ResultTable::query()
				->setSelect(['ID', 'TASK_ID', 'COMMENT_ID', 'CREATED_BY', 'CREATED_AT', 'UPDATED_AT', 'TEXT', 'STATUS', 'UF_*'])
				->where('TASK_ID', $taskId)
				->setOrder(['ID' => 'DESC'])
				->exec()
				->fetchCollection()
			;
	}

	public function getByCommentId(int $commentId): ?\Bitrix\Tasks\Internals\Task\Result\Result
	{
		return ResultTable::query()
				->setSelect(['ID', 'TASK_ID', 'COMMENT_ID', 'CREATED_BY', 'CREATED_AT', 'UPDATED_AT', 'TEXT', 'STATUS'])
				->where('COMMENT_ID', $commentId)
				->exec()
				->fetchObject()
			;
	}

	public function isExists(int $taskId, int $commentId = 0): bool
	{
		$query = ResultTable::query()
			->setSelect([new ExpressionField('1', '1')])
			->where('TASK_ID', $taskId);

		if ($commentId > 0)
		{
			$query->where('COMMENT_ID', $commentId);
		}

		$row = $query->exec()->fetch();

		return !empty($row);
	}

	public function getLast(int $taskId): ?Entity\Result
	{
		$select = [
			'ID',
			'TASK_ID',
			'CREATED_BY',
			'CREATED_AT',
			'UPDATED_AT',
			'TEXT',
			'STATUS',
			'UF_*',
		];

		$result = ResultTable::query()
			->setSelect($select)
			->where('TASK_ID', $taskId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		if ($result === null)
		{
			return null;
		}

		$author = $this->userRepository->getByIds([$result->getCreatedBy()])->findOneById($result->getCreatedBy());

		return $this->taskResultMapper->mapToEntity(
			$result,
			$author,
		);
	}

	public function containsResults(int $taskId): bool
	{
		$result = ResultTable::query()
			->setSelect([new ExpressionField('EXISTS', 1)])
			->where('TASK_ID', $taskId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $result !== false;
	}
}
