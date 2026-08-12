<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\AccessType;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\ItemType;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\OptionalCatalogItemFields;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule\ViewUrlRequiredForApplication;

#[ViewUrlRequiredForApplication]
#[OptionalCatalogItemFields]
class AddRequest extends Request
{
	#[Rule\NotEmpty]
	#[Rule\Length(max: 255)]
	public string $title;

	#[Rule\NotEmpty]
	#[ItemType]
	public string $type;

	#[Rule\NotEmpty]
	#[AccessType]
	public string $accessType;

	public ?string $description = null;

	public ?string $editUrl;

	public ?string $viewUrl;

	public ?string $iconUrl;

	public ?int $chatId;

	public ?string $externalId;

	public ?string $iss = null;
}
