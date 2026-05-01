<?php

namespace Bitrix\Rest\V3\Realisation\Request\Field\Custom\Enum;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;

class DeleteRequest extends \Bitrix\Rest\V3\Interaction\Request\DeleteRequest
{
	public string $entityId;
}