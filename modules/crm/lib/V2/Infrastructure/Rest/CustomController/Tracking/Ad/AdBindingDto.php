<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Ad;

use Bitrix\Rest\V3\Dto\Dto;

final class AdBindingDto extends Dto
{
	public int $sourceId;
	public string $provider;
	public ?string $clientId;
	public ?string $accountId;
}
