<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Balance;

enum VibeBalanceAlert: string
{
	case None = 'none';
	case Low = 'low';
	case Negative = 'negative';
}
