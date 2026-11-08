<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Mail\Integration\HumanResources\NodeAccessCodeResolver;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internal\Async\Message\MailboxMigrationNotificationMessage;
use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

final class MailboxMigrationNotificationReceiver extends AbstractReceiver
{
	private const USERS_PER_MESSAGE = 50;

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof MailboxMigrationNotificationMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!$this->canDeliver($message))
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$mailbox = MailboxTable::getList([
			'select' => ['ID', 'USER_ID', 'EMAIL'],
			'filter' => ['=ID' => $message->mailboxId],
			'limit' => 1,
		])->fetch();
		if (!$mailbox)
		{
			return;
		}

		$userIds = $message->userIds;
		if ($userIds === [])
		{
			foreach (
				$this->chunkUniqueUserIds($this->resolveUsers($message->mailboxId, (int)$mailbox['USER_ID']))
				as $chunk
			)
			{
				(new MailboxMigrationNotificationMessage(
					mailboxId: $message->mailboxId,
					status: $message->status,
					userIds: $chunk,
					operationId: $message->operationId ?? null,
				))->send('mail_migration_notification');
			}

			return;
		}

		foreach ($userIds as $userId)
		{
			Notification::notifyUserAboutMailboxMigration(
				(int)$userId,
				$message->mailboxId,
				(string)$mailbox['EMAIL'],
				$message->status,
			);
		}
	}

	private function canDeliver(MailboxMigrationNotificationMessage $message): bool
	{
		if (in_array($message->status, ['running', 'waiting', 'blocked', 'switching'], true))
		{
			$status = (new MigrationStatusProvider())->getLatest($message->mailboxId);

			return $status !== null && $status->visibility === 'active'
				&& (($message->operationId ?? null) === null || $message->operationId === $status->operationId);
		}

		if (!in_array($message->status, ['done', 'cancelled'], true))
		{
			return false;
		}

		$statusProvider = new MigrationStatusProvider();
		$status = $statusProvider->getLatest($message->mailboxId);
		if ($status === null || $status->visibility === 'active')
		{
			return false;
		}

		$operationId = $message->operationId ?? null;

		if ($operationId === null)
		{
			return true;
		}

		$operation = $status->operationId === $operationId
			? $status
			: $statusProvider->get($message->mailboxId, $operationId);

		return $operation !== null && $operation->visibility === 'terminal';
	}

	/** @return \Generator<int> */
	private function resolveUsers(int $mailboxId, int $ownerId): \Generator
	{
		$rows = MailboxAccessTable::getList([
			'select' => ['ACCESS_CODE'],
			'filter' => ['=MAILBOX_ID' => $mailboxId, '=TASK_ID' => 0],
		])->fetchAll();
		$departments = [];
		$departmentsWithChildren = [];

		foreach (array_column($rows, 'ACCESS_CODE') as $code)
		{
			if (preg_match('/' . AccessCode::AC_USER . '/', (string)$code, $matches))
			{
				yield (int)$matches[2];
			}
			elseif (preg_match('/' . AccessCode::AC_ALL_DEPARTMENT . '/', (string)$code))
			{
				$departmentsWithChildren[] = (string)$code;
			}
			elseif (preg_match('/' . AccessCode::AC_DEPARTMENT . '/', (string)$code))
			{
				$departments[] = (string)$code;
			}
		}

		if ($departments !== [])
		{
			$nodeIds = NodeAccessCodeResolver::resolveNodeIds($departments);
			yield from NodeMemberService::getPagedMemberIdsByDepartmentIds($nodeIds);
		}

		if ($departmentsWithChildren !== [])
		{
			$nodeIds = NodeAccessCodeResolver::resolveNodeIds($departmentsWithChildren);
			yield from NodeMemberService::getPagedMemberIdsByDepartmentIds($nodeIds, true);
		}

		yield $ownerId;
	}

	/**
	 * @param iterable<int> $userIds
	 * @return \Generator<int[]>
	 */
	private function chunkUniqueUserIds(iterable $userIds): \Generator
	{
		$seenUserIds = [];
		$chunk = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId <= 0 || isset($seenUserIds[$userId]))
			{
				continue;
			}

			$seenUserIds[$userId] = true;
			$chunk[] = $userId;
			if (count($chunk) === self::USERS_PER_MESSAGE)
			{
				yield $chunk;
				$chunk = [];
			}
		}

		if ($chunk !== [])
		{
			yield $chunk;
		}
	}
}
