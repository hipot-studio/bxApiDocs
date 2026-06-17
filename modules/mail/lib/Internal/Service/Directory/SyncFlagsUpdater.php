<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class SyncFlagsUpdater
{
	/**
	 * @param array<int, array{dirMd5?: string, value?: int|string}> $dirs
	 */
	public function update(int $mailboxId, array $dirs): Result
	{
		$result = new Result();

		try
		{
			(new MailboxDirectoryHelper($mailboxId))->toggleSyncDirs($dirs);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage(), 'MAIL_CLIENT_DIRS_SYNC_ERROR'));
		}

		return $result;
	}
}
