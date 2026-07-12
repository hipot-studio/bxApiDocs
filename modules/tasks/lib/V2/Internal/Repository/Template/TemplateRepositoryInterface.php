<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;

interface TemplateRepositoryInterface
{
	public function getById(int $id): ?Template;

	public function getByTaskId(int $taskId): ?Template;

	public function save(Template $entity): int;

	public function delete(int $id): void;

	public function invalidate(int $id): void;

	public function getReplicateParams(int $templateId): ?array;

	public function getReplicableTemplateIds(int $afterId, int $limit): array;
}
