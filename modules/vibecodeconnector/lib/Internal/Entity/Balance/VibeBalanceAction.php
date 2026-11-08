<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Balance;

enum VibeBalanceAction: string
{
	case Purchase = 'purchase';
	case Request = 'request';
	case Requested = 'requested';
	case None = 'none';
}
