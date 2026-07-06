<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\IncomingWebhook;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class IncomingWebhookAttributeDto extends Dto
{
	#[Title("Incoming webhook attribute code")]
	#[Filterable, Sortable]
	public string $code;

	#[Title("Incoming webhook attribute value")]
	#[Editable]
	public mixed $value;
}
