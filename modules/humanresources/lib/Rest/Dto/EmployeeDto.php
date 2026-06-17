<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class EmployeeDto extends Dto
{
	public ?int $userId;

	public ?string $name;

	public ?string $workPosition;

	public ?string $avatar;

	public ?string $url;

	public ?array $departments;

	public ?array $teams;
}
