<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

interface RelatedTaskTemplateRepositoryInterface
{
	public function getRelatedTaskIds(int $templateId): array;

	public function containsRelatedTasks(int $templateId): bool;

	public function save(int $templateId, array $relatedTaskIds): void;

	public function deleteByRelatedTaskIds(int $templateId, array $relatedTaskIds): void;
}
