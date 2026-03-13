<?php

namespace Bitrix\Rest\V3\Realisation\Request\Field;

use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class ListRequest extends Request
{
	#[Hidden]
	public string $dtoClass;

	public ?SelectStructure $select = null;
}