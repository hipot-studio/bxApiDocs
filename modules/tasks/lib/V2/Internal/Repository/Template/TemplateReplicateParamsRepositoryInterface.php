<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateReplicateParams;

interface TemplateReplicateParamsRepositoryInterface
{
	public function getByTaskId(int $taskId): ?TemplateReplicateParams;

	/** @return array<int, TemplateReplicateParams> */
	public function getByTaskIds(array $taskIds): array;
	public function getByTemplateId(int $templateId): ?TemplateReplicateParams;

	/** @return array<int, TemplateReplicateParams> */
	public function getByTemplateIds(array $templateIds): array;
	public function invalidateByTemplateId(int $templateId): void;
	public function invalidateByTaskId(int $taskId): void;
}
