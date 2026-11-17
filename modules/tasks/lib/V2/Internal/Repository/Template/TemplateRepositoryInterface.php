<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Template;

interface TemplateRepositoryInterface
{
	public function getById(int $id): ?Template;

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	public function filterExistingIds(array $ids): array;

	public function getByTaskId(int $taskId): ?Template;

	public function getTaskByTemplateId(int $templateId): ?Task;

	public function save(Template $entity): int;

	public function delete(int $id): void;

	public function invalidate(int $id): void;

	/**
	 * Acquires a row-level lock within the current transaction.
	 */
	public function lockById(int $id): void;

	public function isReplicable(int $id): bool;

	public function getReplicateParams(int $templateId): ?array;

	public function getReplicableTemplateIds(int $afterId, int $limit): array;
}
