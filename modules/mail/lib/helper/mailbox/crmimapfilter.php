<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\MailFilterTable;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

// Error phrase is shared with the mailbox connect surface and lives in its lang file.
Loc::loadMessages(__DIR__ . '/mailboxconnector.php');

/**
 * The only writer of 'crm_imap' filter rows: they are the actual gate of CRM mail processing.
 */
final class CrmImapFilter
{
	private const ACTION_TYPE = 'crm_imap';

	private const ADD_FAILED_ERROR_CODE = 'MAIL_CRM_IMAP_FILTER_ADD_FAILED';

	public static function sync(
		int $mailboxId,
		bool $shouldBeConnected,
		CrmImapFilterContext $context = CrmImapFilterContext::MailboxConnect,
	): Result
	{
		$result = new Result();

		$ids = [];
		$activeIds = [];
		foreach (self::fetchFilters($mailboxId) as $filter)
		{
			$id = (int)$filter['ID'];
			$ids[] = $id;

			if ($filter['ACTIVE'] === 'Y' && $filter['WHEN_MAIL_RECEIVED'] === 'Y')
			{
				$activeIds[] = $id;
			}
		}

		if (!$shouldBeConnected)
		{
			self::deleteFilters($ids);

			return $result;
		}

		$staleIds = array_values(array_diff($ids, $activeIds));
		if (count($activeIds) === 1 && $staleIds === [])
		{
			return $result;
		}

		$keptId = $activeIds[0] ?? null;
		self::deleteFilters($keptId === null ? $ids : array_diff($ids, [$keptId]));

		if ($keptId !== null)
		{
			return $result;
		}

		$addedId = \CMailFilter::add([
			'MAILBOX_ID' => $mailboxId,
			'NAME' => 'CRM IMAP ' . $mailboxId,
			'ACTION_TYPE' => self::ACTION_TYPE,
			'WHEN_MAIL_RECEIVED' => 'Y',
			'WHEN_MANUALLY_RUN' => 'Y',
		]);

		if (!$addedId)
		{
			$result->addError(new Error(
				(string)Loc::getMessage(self::getAddFailedMessageId($context)),
				self::ADD_FAILED_ERROR_CODE,
			));
		}

		return $result;
	}

	public static function isConnected(int $mailboxId): bool
	{
		return self::getConnectedMap([$mailboxId])[$mailboxId] ?? false;
	}

	/**
	 * @param int[] $mailboxIds
	 * @return array<int, bool> actual gate state of every requested mailbox
	 */
	public static function getConnectedMap(array $mailboxIds): array
	{
		$ids = array_values(array_unique(array_map('intval', $mailboxIds)));
		if ($ids === [])
		{
			return [];
		}

		$connected = array_fill_keys($ids, false);

		$rows = MailFilterTable::getList([
			'select' => ['MAILBOX_ID'],
			'filter' => [
				'@MAILBOX_ID' => $ids,
				'=ACTION_TYPE' => self::ACTION_TYPE,
				'=ACTIVE' => 'Y',
				'=WHEN_MAIL_RECEIVED' => 'Y',
			],
			'group' => ['MAILBOX_ID'],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$connected[(int)$row['MAILBOX_ID']] = true;
		}

		return $connected;
	}

	private static function getAddFailedMessageId(CrmImapFilterContext $context): string
	{
		return match ($context)
		{
			CrmImapFilterContext::MailboxConnect => 'MAIL_MAILBOX_CONNECTOR_CRM_IMAP_FILTER_ADD_FAILED',
			CrmImapFilterContext::SettingsSave => 'MAIL_MAILBOX_CONNECTOR_CRM_IMAP_FILTER_ADD_FAILED_ON_SAVE',
		};
	}

	private static function fetchFilters(int $mailboxId): array
	{
		return MailFilterTable::getList([
			'select' => ['ID', 'ACTIVE', 'WHEN_MAIL_RECEIVED'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ACTION_TYPE' => self::ACTION_TYPE,
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	private static function deleteFilters(array $ids): void
	{
		foreach ($ids as $id)
		{
			\CMailFilter::delete($id);
		}
	}
}
