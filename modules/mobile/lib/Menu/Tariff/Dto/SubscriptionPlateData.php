<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff\Dto;

use Bitrix\Mobile\Dto\Dto;

final class SubscriptionPlateData extends Dto
{
	public string $state;
	public ?string $expireText;
	public bool $isDemoAvailable;
	public bool $cta;
	public bool $ctaForced;
	public bool $canBuy;
}
