<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internal\Async\Message\MailboxMigrationNotificationMessage;
use Bitrix\Main\Loader;

class MigrationUserStatusPublisher
{
	public const COMMAND = 'mailbox_migration_status_changed';

	public function __construct(
		private readonly MigrationStatusProvider $statusProvider = new MigrationStatusProvider(),
	)
	{
	}

	public function publish(int $mailboxId, string $operationId, bool $notify = false): void
	{
		$status = $this->statusProvider->getForDisplay($mailboxId, $operationId);
		if ($status === null || $status->visibility === 'hidden')
		{
			return;
		}

		$payload = $status->toPublicArray();
		if (Loader::includeModule('pull'))
		{
			\CPullWatch::addToStack(
				['mail_mailbox_' . $mailboxId, 'mail_mailbox_migration_' . $mailboxId],
				[
					'module_id' => 'mail',
					'command' => self::COMMAND,
					'params' => $payload,
				],
			);
		}

		if ($notify && in_array($status->publicStatus, [
			'running', 'waiting', 'blocked', 'switching', 'done', 'cancelled',
		], true))
		{
			(new MailboxMigrationNotificationMessage(
				mailboxId: $mailboxId,
				status: (string)$status->publicStatus,
				operationId: $operationId,
			))->send('mail_migration_notification');
		}
	}
}
