<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator;

enum TriggeredFactor: string
{
	case Duration = 'DURATION';
	case PeriodWide = 'PERIOD_WIDE';
	case ManyColumns = 'MANY_COLUMNS';
	case NoFilters = 'NO_FILTERS';
	case LargeData = 'LARGE_DATA';
}
