<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationLock;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class DeletedMailboxReceiver
{
	public const ERROR_NOT_SWITCHED = 'MAIL_DELETED_MAILBOX_NOT_SWITCHED';
	public const ERROR_MISMATCH = 'MAIL_DELETED_MAILBOX_MISMATCH';
	public const ERROR_UPDATE_FAILED = 'MAIL_DELETED_MAILBOX_UPDATE_FAILED';
	public const ERROR_OPERATION_BUSY = 'MAIL_DELETED_MAILBOX_OPERATION_BUSY';

	public function accept(int $mailboxId, string $operationId, string $email): Result
	{
		$result = new Result();
		if ($mailboxId <= 0 || $operationId === '' || $email === '')
		{
			return $result->addError(new Error('Invalid deleted mailbox identity', self::ERROR_MISMATCH));
		}
		if (!OperationLock::acquire($mailboxId))
		{
			return $result->addError(new Error('Mailbox operation is busy', self::ERROR_OPERATION_BUSY));
		}

		try
		{
			return $this->markUnavailable($mailboxId, $operationId, $email);
		}
		finally
		{
			OperationLock::release($mailboxId);
		}
	}

	private function markUnavailable(int $mailboxId, string $operationId, string $email): Result
	{
		$result = new Result();

		$mailbox = MailboxTable::query()
			->setSelect(['ID', 'ACTIVE', 'EMAIL', 'ACTIVE_GENERATION_ID'])
			->where('ID', $mailboxId)
			->setLimit(1)
			->fetch()
		;
		if (!$mailbox || mb_strtolower((string)$mailbox['EMAIL']) !== mb_strtolower($email))
		{
			return $result->addError(new Error('Deleted mailbox does not match', self::ERROR_MISMATCH));
		}

		$generationId = (int)$mailbox['ACTIVE_GENERATION_ID'];
		$generation = $generationId > 0 ? MailboxSourceGenerationTable::query()
			->setSelect(['ID', 'MAILBOX_ID', 'OPERATION_ID', 'STATUS', 'SERVICE_NAME'])
			->where('ID', $generationId)
			->setLimit(1)
			->fetch() : false
		;
		$operation = MigrationOperationTable::query()
			->setSelect(['GENERATION_ID', 'STAGE'])
			->where('MAILBOX_ID', $mailboxId)
			->where('OPERATION_ID', $operationId)
			->setLimit(1)
			->fetch()
		;
		if (!$generation || !$operation
			|| (int)$generation['MAILBOX_ID'] !== $mailboxId
			|| (string)$generation['OPERATION_ID'] !== $operationId
			|| (string)$generation['STATUS'] !== MailboxSourceGenerationTable::STATUS_ACTIVE
			|| (string)$generation['SERVICE_NAME'] !== 'mailhost'
			|| (int)$operation['GENERATION_ID'] !== $generationId
			|| (string)$operation['STAGE'] !== 'SWITCHED')
		{
			return $result->addError(new Error('Mailbox has not switched to Mailhost', self::ERROR_NOT_SWITCHED));
		}

		if ((string)$mailbox['ACTIVE'] === MailboxStatus::Inactive->value)
		{
			return $result->setData(['changed' => false]);
		}
		if ((string)$mailbox['ACTIVE'] !== MailboxStatus::Active->value)
		{
			return $result->addError(new Error('Mailbox is not active', self::ERROR_MISMATCH));
		}

		$updated = MailboxTable::update($mailboxId, ['ACTIVE' => MailboxStatus::Inactive->value]);
		if (!$updated->isSuccess())
		{
			return $result->addError(new Error('Mailbox availability was not saved', self::ERROR_UPDATE_FAILED));
		}

		return $result->setData(['changed' => true]);
	}
}
