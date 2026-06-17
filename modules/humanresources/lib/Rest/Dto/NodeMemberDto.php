<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class NodeMemberDto extends Dto
{
	public ?int $userId;

	public ?string $name;

	public ?string $workPosition;

	public ?string $role;

	public ?string $avatar;

	public ?string $url;
}
