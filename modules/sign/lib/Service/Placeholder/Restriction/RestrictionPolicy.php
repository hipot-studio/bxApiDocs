<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

/**
 * How a rule treats an undefined scenario axis.
 *
 * PERMISSIVE keeps the field visible (placeholder list), STRICT restricts it (send warning).
 */
enum RestrictionPolicy
{
	case PERMISSIVE;
	case STRICT;
}
