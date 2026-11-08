<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder;

enum Direction: string
{
	case Backward = 'backward';
	case Around = 'around';
}
