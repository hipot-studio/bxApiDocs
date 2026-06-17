<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class RecipientDto extends Dto
{
	public ?int $id;

	public ?string $email;

	public ?string $name;
}
