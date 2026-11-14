<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Enum;

/**
 * Availability of Disk restrictions on the portal and the reason behind it.
 * The interface reacts to the reason: a restricted tariff offers a purchase popup,
 * while a restricted edition or a disabled flag hides the section.
 *
 * The state is answered by a public contract of the module, so the type lives in the public
 * part of disk: a consumer of that contract names the state without reaching into the domain.
 */
enum RestrictionsAvailabilityState: string
{
	case Applied = 'applied';
	case TariffRestricted = 'tariff_restricted';
	case EditionRestricted = 'edition_restricted';
	case DisabledByFlag = 'disabled_by_flag';
}
