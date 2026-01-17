<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Internals\Task\Result\EO_Result_Collection;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class TaskResultMapper
{
	public function mapToEntity(
		\Bitrix\Tasks\Internals\Task\Result\Result $result,
		?User $author = null,
	): Result
	{
		$files = $result->get(ResultTable::UF_FILE_NAME);

		return new Result(
			id: $result->getId(),
			taskId: $result->getTaskId(),
			text: $result->getText(),
			author: $author,
			createdAtTs: $result->getCreatedAt() ? $result->getCreatedAt()->getTimestamp() : null,
			updatedAtTs: $result->getUpdatedAt() ? $result->getUpdatedAt()->getTimestamp() : null,
			status: Result\Status::fromRaw($result->getStatus()),
			fileIds: is_array($files) ? $files : null,
			messageId: $result->getMessage()?->getMessageId(),
		);
	}

	public function mapToCollection(
		EO_Result_Collection $results,
		?UserCollection $authors = null,
	): ResultCollection
	{
		$entities = [];
		foreach ($results as $result)
		{

			$entities[] = $this->mapToEntity(
				$result,
				$authors?->findOneById($result->getCreatedBy())
			);
		}

		return new ResultCollection(...$entities);
	}
}
