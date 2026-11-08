<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes;

interface ProjectAvailabilitySource
{
	/**
	 * @param list<int> $projectIds
	 */
	public function preloadExistence(array $projectIds): void;

	/**
	 * @param list<int> $projectIds
	 */
	public function preloadVisibility(array $projectIds, int $userId): void;

	public function projectExists(int $projectId): ?bool;

	public function projectVisible(int $projectId, int $userId): bool;
}
