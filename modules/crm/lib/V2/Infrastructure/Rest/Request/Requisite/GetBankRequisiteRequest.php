<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Rest\V3\Structure\SelectStructure;

final class GetBankRequisiteRequest extends AbstractBankRequisiteIdRequest
{
	public ?SelectStructure $select = null;
}
