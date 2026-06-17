<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Mail;

use Bitrix\Main\Loader;

final class MailConnectionRequestService
{
	public static function getResponsibleAdminId(): int
	{
		if (!Loader::includeModule('mail'))
		{
			return 0;
		}

		return (new \Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService())
			->getResponsibleAdminId();
	}

	public static function setResponsibleAdminId(int $adminId): void
	{
		if (!Loader::includeModule('mail'))
		{
			return;
		}

		(new \Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService())
			->setResponsibleAdminId($adminId);
	}
}
