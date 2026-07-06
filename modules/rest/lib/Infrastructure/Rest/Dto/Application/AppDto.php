<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class AppDto extends Dto
{
	#[Title("Application inner identification")]
	#[Filterable, Sortable]
	public int $id;

	#[Title("Application client ID")]
	#[Filterable, Sortable]
	public string $clientId;

	#[Title("Application client secret")]
	#[Optional]
	public ?string $clientSecret;

	#[Title("Application token (shared secret used to authenticate webhook callbacks from the portal)")]
	#[Optional]
	public ?string $applicationToken;

	#[Title("Application scopes")]
	#[Editable]
	public ?array $scopes;

	#[Title("Application title")]
	#[Filterable, Sortable]
	public ?string $title;

	#[Title("Handler URL")]
	public ?string $url;

	#[Title("Installation URL")]
	public ?string $urlInstall;

	#[Title("Settings URL")]
	public ?string $urlSettings;

	#[Title("Mobile application flag")]
	#[Filterable]
	public bool $mobile;

	#[Title("Application version")]
	#[Sortable]
	public ?string $version;

	#[Title("Application active flag")]
	#[Filterable]
	public bool $active;

	#[Title("Application installed flag")]
	#[Filterable]
	public bool $installed;

	#[Title("Date of creation")]
	#[Filterable, Sortable]
	#[Optional]
	public ?string $dateCreate;

	#[Title("Date of installation")]
	#[Filterable, Sortable]
	#[Optional]
	public ?string $dateInstall;

	#[Title("Application external attributes")]
	#[Optional, Editable]
	public ?array $attributes;
}
