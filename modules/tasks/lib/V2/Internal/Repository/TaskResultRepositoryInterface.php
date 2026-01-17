<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Task\Result\EO_Result_Collection;
use Bitrix\Tasks\Internals\Task\Result\Result;
use Bitrix\Tasks\V2\Internal\Entity;

interface TaskResultRepositoryInterface
{
	public function isResultRequired(int $taskId): bool;

	public function getById(int $resultId): Entity\Result|null;

	public function getByIds(array $resultIds): Entity\ResultCollection;

	public function getByTask(int $taskId, ?int $limit = null, ?int $offset = null): Entity\ResultCollection;

	public function getAttachmentIdsByResult(int $resultId): ?array;

	public function getResultMessageMap(int $taskId): array;

	public function save(Entity\Result $entity, int $userId): int;

	public function delete(int $id, int $userId): void;

	public function deleteMessageLink(int $messageId): void;

	public function getByTaskId(int $taskId): EO_Result_Collection;

	public function getByCommentId(int $commentId): ?Result;

	public function isExists(int $taskId, int $commentId = 0): bool;

	public function getLast(int $taskId): ?Entity\Result;

	public function containsResults(int $taskId): bool;
}
