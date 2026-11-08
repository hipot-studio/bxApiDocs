<?php

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

final class SubscribableEventDto extends Dto
{
	#[Filterable]
	public string $module;
	#[Filterable]
	public string $name;
	#[Filterable]
	public string $type;

	public ?string $title;

	public ?string $description;

	public array $scopes;

	public array $selectFields;
}