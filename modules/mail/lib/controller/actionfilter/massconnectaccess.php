<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Error;

/**
 * The gate of mass connect and of the flows around it, passwordless connect among them. It knows
 * nothing of the switch of the grid bulk actions: that switch closes the panel above the grid and the
 * actions behind it, and never the connection of a mailbox.
 */
final class MassConnectAccess extends MassAccessBase
{
	protected function getDenial(): ?Error
	{
		return MailAccess::getMassConnectDenial();
	}
}
