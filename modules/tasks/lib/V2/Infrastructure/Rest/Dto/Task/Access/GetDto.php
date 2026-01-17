<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\Access;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

class GetDto extends Dto
{
	#[Filterable]
	public ?int $taskId;

	#[Filterable]
	public ?int $userId;

	public ?array $rights;
}
