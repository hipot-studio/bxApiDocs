<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Error;

/**
 * The gate of the mass actions of the mailbox grid: the same conditions as mass connect plus the
 * switch of the bulk actions, which is exactly what the panel above the grid is shown by.
 */
final class MassMailboxActionsAccess extends MassAccessBase
{
	protected function getDenial(): ?Error
	{
		return MailAccess::getMassMailboxActionsDenial();
	}
}
