<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\State;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum StateFlagKey: string
{
	use EnumValuesTrait;

	case NeedsControl = 'needsControl';
	case MatchesWorkTime = 'matchesWorkTime';
	case DefaultRequireResult = 'defaultRequireResult';
	case AllowsTimeTracking = 'allowsTimeTracking';
}
