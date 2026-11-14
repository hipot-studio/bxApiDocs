<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

/**
 * The scenario the tariff table picks a plan by. The values are the wire format of the plate data
 * and are repeated by the allowlist of the tariff table page.
 */
enum TariffTableSelection: string
{
	case FirstCompatibleIfRestricted = 'first-compatible-if-restricted';
	case FirstCompatible = 'first-compatible';
	case NextCompatible = 'next-compatible';
	case CurrentOrFirstCompatible = 'current-or-first-compatible';
}
