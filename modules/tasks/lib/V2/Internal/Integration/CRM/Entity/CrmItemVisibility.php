<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Entity;

/**
 * How a CRM binding may be shown to a concrete user. Deleted and forbidden are distinct states: an empty
 * title must never be read as "no access", and no access must never be read as "deleted".
 */
enum CrmItemVisibility: string
{
	case Readable = 'readable';
	case Hidden = 'hidden';
	case NotFound = 'notFound';
}
