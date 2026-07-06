<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\IncomingWebhook;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class IncomingWebhookDto extends Dto
{
	#[Title("Webhook handler URL")]
	public string $url;

	#[Title("Webhook scopes")]
	#[Editable]
	public array $scopes;

	#[Title("Webhook title")]
	#[Editable]
	#[Filterable]
	public ?string $title;

	#[Title("Active flag")]
	#[Filterable]
	public bool $active;

	#[Title("Owner user id")]
	#[Filterable]
	public ?int $userId;

	#[Title("Date of creation")]
	#[Sortable]
	#[Optional]
	#[Filterable]
	public ?string $dateCreate;

	#[Title("Incoming webhook external attributes")]
	#[Optional]
	public ?array $attributes;
}
