<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MailServiceInstaller;
use Bitrix\Mail\MailServicesTable;

final class ActiveMailboxService
{
	public static function resolve(array $mailbox): ?array
	{
		if ((int)($mailbox['SERVICE_ID'] ?? 0) > 0)
		{
			return MailServicesTable::getById($mailbox['SERVICE_ID'])->fetch() ?: null;
		}

		$generationId = (int)($mailbox['ACTIVE_GENERATION_ID'] ?? 0);
		if ($generationId <= 0)
		{
			return null;
		}

		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'STATUS', 'SERVICE_NAME', 'OPERATION_ID'],
			'filter' => ['=ID' => $generationId],
			'limit' => 1,
		])->fetch();
		$name = self::getUnboundServiceName($mailbox, $generation ?: []);
		if ($name === null)
		{
			return null;
		}

		$serviceId = MailServicesTable::findImapServiceId((string)$mailbox['LID'], $name);

		return ($serviceId > 0 ? MailServicesTable::getById($serviceId)->fetch() : null)
			?: MailServiceInstaller::getMailhostService()
		;
	}

	public static function getUnboundServiceName(array $mailbox, array $generation): ?string
	{
		if ((int)($mailbox['SERVICE_ID'] ?? 0) > 0
			|| (int)($mailbox['ACTIVE_GENERATION_ID'] ?? 0) <= 0
			|| (int)$mailbox['ACTIVE_GENERATION_ID'] !== (int)($generation['ID'] ?? 0)
			|| (int)($mailbox['ID'] ?? 0) !== (int)($generation['MAILBOX_ID'] ?? 0)
			|| ($generation['STATUS'] ?? '') !== MailboxSourceGenerationTable::STATUS_ACTIVE
			|| ($generation['SERVICE_NAME'] ?? '') !== 'mailhost'
			|| empty($generation['OPERATION_ID'])
			|| $generation['OPERATION_ID'] === MailboxSourceGenerationTable::OPERATION_G1_BACKFILL
		)
		{
			return null;
		}

		return 'mailhost';
	}
}
