<?php

namespace Bitrix\Rest\V3\Realisation\Dto\App;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class ScopeRequestStatusDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

	public int $requestId;

	public string $status;

	public string $comment;

	public DateTime $createdAt;
}