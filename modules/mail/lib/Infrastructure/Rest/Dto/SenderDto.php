<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class SenderDto extends Dto
{
	public ?string $email;

	public ?string $name;

	public ?string $sender;
}
