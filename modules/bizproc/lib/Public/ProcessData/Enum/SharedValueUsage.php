<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Enum;

/**
 * What the portal answers about a shared value. `Unknown` is not a third kind of usage but the absence of an
 * answer: the reading of the portal spent its budget, and a value neither held nor freed is refused by the
 * caller instead of being guessed about.
 */
enum SharedValueUsage
{
	case Used;
	case Free;
	case Unknown;
}
