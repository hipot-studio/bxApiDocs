<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * Why a change of a source is refused although the values are valid: the state of the source, of the portal
 * or of a parallel request does not allow it.
 *
 * @internal
 */
enum SourceRefusal
{
	/** The code names an archived record: a new record of the code would be a duplicate. */
	case Archived;

	/** Organic traffic, email marketing and 1C without a record have nothing to set up or to archive. */
	case NotConfigurable;

	/** Another record of the code is active: a second active record of a code is not created. */
	case ActiveExists;

	/** VKontakte and VK Ads cannot be set up in the zone of the portal. */
	case RegionUnavailable;

	/** The tariff of the portal has no own sources. */
	case TariffRestricted;

	/** A parallel request writes the same code. */
	case Busy;
}
