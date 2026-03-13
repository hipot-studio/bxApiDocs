<?php

namespace Bitrix\HumanResources\Enum;

/**
 * Enum for modes for filters used in Builders
 * Use it to specify whether to use "WHERE IN" or "WHERE NOT IN" condition
 */
enum ConditionMode: string
{
	case Inclusion = 'inclusion';
	case Exclusion = 'exclusion';
}
