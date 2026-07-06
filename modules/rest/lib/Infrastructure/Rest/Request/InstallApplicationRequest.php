<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Url;
use Bitrix\Rest\Internal\Validation\Rule\ExternalAttributes;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class InstallApplicationRequest extends Request
{
	public string $title;

	#[Url]
	public string $handlerUrl;

	#[NotEmpty]
	#[ElementsType(typeEnum: Type::String)]
	public array $scopes;

	public bool $mobile = false;
	public array $menuTitles = [];
	public ?string $clientId = null;

	#[Length(min: 16, max: 50)]
	public ?string $applicationToken;

	#[ExternalAttributes]
	public array $attributes = [];

	public ?SelectStructure $select = null;
}
