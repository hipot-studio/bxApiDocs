<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Embedding;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;

class AddRequest extends Request
{
	#[Rule\PositiveNumber]
	public int $catalogItemId;

	#[Rule\NotEmpty]
	public string $placement;

	#[Rule\NotEmpty]
	public string $handler;

	public array $options = [];

	public array $languages = [];
}
