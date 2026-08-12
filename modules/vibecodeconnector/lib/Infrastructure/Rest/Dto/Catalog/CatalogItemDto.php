<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\AccessType;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\OptionalCatalogItemFields;

#[OptionalCatalogItemFields]
class CatalogItemDto extends Dto
{
	#[Title('Catalog item identifier')]
	public int $catalogItemId;

	#[Title('Title')]
	#[Editable]
	#[Rule\NotEmpty]
	#[Rule\Length(max: 255)]
	public string $title;

	#[Title('Item type')]
	public string $type;

	#[Title('Access type')]
	#[Editable]
	#[AccessType]
	public string $accessType;

	#[Title('Description')]
	#[Editable]
	#[Optional]
	public ?string $description;

	#[Title('Edit URL')]
	#[Editable]
	#[Optional]
	public ?string $editUrl;

	#[Title('View URL')]
	#[Editable]
	#[Optional]
	public ?string $viewUrl;

	#[Title('Icon URL')]
	#[Editable]
	#[Optional]
	public ?string $iconUrl;

	#[Title('Chat identifier')]
	#[Editable]
	#[Optional]
	public ?int $chatId;

	#[Title('External identifier')]
	#[Editable]
	#[Optional]
	public ?string $externalId;

	#[Title('Owner user identifier')]
	public int $ownerId;

	#[Title('Color')]
	public string $color;

	#[Title('Date of creation (ISO-8601)')]
	#[Optional]
	public ?string $createdAt;

	#[Title('Date of last update (ISO-8601)')]
	#[Optional]
	public ?string $updatedAt;
}
