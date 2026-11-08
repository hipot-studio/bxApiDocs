<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

class GanttLinkDto extends Dto
{
	#[Filterable]
	public ?int $taskId;

	public ?int $dependentId;

	public ?string $type;

	public ?int $creatorId;
}
