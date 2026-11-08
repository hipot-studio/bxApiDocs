<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

final class AgreementItemDto extends Dto
{
	public int $agreementId;

	public bool $checked;

	public bool $required;
}
