<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

/**
 * The kind of value a fact source answers with; it decides which operators a condition on the fact
 * may use.
 */
enum FactType
{
	case Boolean;
	case Number;
	case Date;
	case EnumValue;
	case StringList;
}
