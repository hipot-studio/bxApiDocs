<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration;

use Bitrix\Rest\V3\Dto\Dto;

final class NamedCodeDto extends Dto
{
	public string $id;

	public string $name;
}
