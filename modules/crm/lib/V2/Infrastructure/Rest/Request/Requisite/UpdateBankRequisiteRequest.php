<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Rest\V3\Structure\FieldsStructure;

final class UpdateBankRequisiteRequest extends AbstractBankRequisiteIdRequest
{
	public FieldsStructure $fields;
}
