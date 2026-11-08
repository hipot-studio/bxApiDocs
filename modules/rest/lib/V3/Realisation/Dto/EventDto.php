<?php

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Main\Validation\Rule\Url;
use Bitrix\Rest\EventTable;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(EventTable::class)]
final class EventDto extends Dto
{
	public int $id;

	public ?int $appId;

	#[Required(['bind', 'unbind'])]
	public string $name;

	#[Required(['bind'])]
	#[Editable(['bind', 'unbind'])]
	#[Url]
	public string $handler;

	#[Filterable]
	public ?int $userId;

	public ?string $title;

	public ?string $comment;

	#[Editable(['bind'])]
	#[Description('Event handler options')]
	public array $options;

	#[Editable(['bind'])]
	public ?string $applicationToken;

	#[Editable(['bind'])]
	public array $selectFields;

	#[Editable(['bind'])]
	public array $filterFields;

	#[Editable(['bind'])]
	public array $updateFields;
}
