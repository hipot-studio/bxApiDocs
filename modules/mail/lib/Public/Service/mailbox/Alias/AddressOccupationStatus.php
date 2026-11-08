<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Mailbox\Alias;

enum AddressOccupationStatus: int
{
	case Free = 0;
	case OccupiedByMailbox = 1;
	case OccupiedByAlias = 2;
	case OccupiedByOrphanAlias = 3;
}
