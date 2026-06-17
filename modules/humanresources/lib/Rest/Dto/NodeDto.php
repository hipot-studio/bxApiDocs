<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class NodeDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Sortable]
	public ?string $name;

	#[Filterable]
	public ?string $type;

	#[Filterable]
	public ?int $structureId;

	#[Filterable]
	public ?int $parentId;

	public ?string $description;

	public ?string $accessCode;

	public ?int $userCount;

	public ?string $colorName;

	public ?string $xmlId;

	#[Sortable]
	public ?string $createdAt;

	public ?string $updatedAt;

	public ?array $members;
}
