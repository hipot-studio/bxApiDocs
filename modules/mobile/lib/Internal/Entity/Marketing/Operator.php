<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

/**
 * How a condition compares a fact with its parameters: membership in a set (an intersection for a
 * list fact), a number range and a date interval with optional inclusive bounds, and one of the values
 * the fact declares.
 */
enum Operator
{
	case InSet;
	case Range;
	case DateRange;
	case Enumeration;
}
