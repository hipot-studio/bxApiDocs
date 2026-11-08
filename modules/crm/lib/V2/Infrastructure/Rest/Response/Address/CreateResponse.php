<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressIdentifierDto;
use Bitrix\Rest\V3\Interaction\Response\Response;

final class CreateResponse extends Response
{
	public function __construct(public RequisiteAddressIdentifierDto $id)
	{
	}
}
