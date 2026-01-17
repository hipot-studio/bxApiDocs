<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLink;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLinkCollection;

interface GanttLinkRepositoryInterface
{
	public function getLinkTypes(int $taskId, array $dependentIds): array;
	public function getTaskLinks(int $taskId): GanttLinkCollection;

	public function update(GanttLink $ganttLink): void;

	public function containsLinks(int $taskId): bool;
}
