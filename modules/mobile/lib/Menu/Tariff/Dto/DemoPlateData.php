<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff\Dto;

use Bitrix\Mobile\Dto\Dto;

/**
 * Narrow tariff payload: the fields the demo plate needs, the only tariff card the menu renders
 * while the "My company" section is on. Kept apart from TariffPlateData so the full menu branch does
 * not have to loosen its types for fields the demo plate never ships.
 */
final class DemoPlateData extends Dto
{
	public string $state;
	public bool $isDemoAvailable;
	public bool $cta;
	public bool $ctaForced;
}
