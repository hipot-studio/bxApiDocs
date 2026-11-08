<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

/**
 * Values are wire values of the placeholder validation response.
 */
enum RestrictionReason: string
{
	case BROKEN = 'broken';
	case SCENARIO_FORBIDDEN = 'scenario_forbidden';
	case REGION_UNAVAILABLE = 'region_unavailable';
	case TYPE_UNSUPPORTED = 'type_unsupported';
}
