<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff\Dto;

use Bitrix\Mobile\Dto\Dto;

final class TariffPlateData extends Dto
{
	public string $name;
	public string $type;
	public string $family;
	public string $state;
	public ?string $expireText;
	public ?int $daysLeft;
	public bool $cta;
	public bool $ctaForced;
	public bool $isDemoAvailable;
}
