<?php

namespace Bitrix\Rest\V3\Realisation\Dto\App;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class ScopeRequestDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

	public int $appId;

	#[Required(['add'])]
	#[NotEmpty]
	public array $scopes;

	public string $status;

	public ScopeRequestStatusDto $currentState;

	#[Required(['add'])]
	#[NotEmpty]
	public string $comment;

	public DateTime $createdAt;

	public DtoCollection $history;
}