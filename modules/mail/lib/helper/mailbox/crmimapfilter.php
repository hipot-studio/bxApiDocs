<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\Internal\Repository\SqlRowLock;
use Bitrix\Mail\MailFilterTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
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
		bool $lockFilters = false,
	): Result
	{
		$result = new Result();

		$ids = [];
		$activeIds = [];
		foreach (self::fetchFilters($mailboxId, $lockFilters) as $filter)
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
			if (!self::deleteFilters($ids, $lockFilters))
			{
				$result->addError(new Error('The CRM filter was not deleted', 'MAIL_CRM_IMAP_FILTER_DELETE_FAILED'));
			}

			return $result;
		}

		$staleIds = array_values(array_diff($ids, $activeIds));
		if (count($activeIds) === 1 && $staleIds === [])
		{
			return $result;
		}

		$keptId = $activeIds[0] ?? null;
		if (!self::deleteFilters($keptId === null ? $ids : array_diff($ids, [$keptId]), $lockFilters))
		{
			return $result->addError(new Error('The CRM filter was not deleted', 'MAIL_CRM_IMAP_FILTER_DELETE_FAILED'));
		}

		if ($keptId !== null)
		{
			return $result;
		}

		$fields = [
			'MAILBOX_ID' => $mailboxId,
			'NAME' => 'CRM IMAP ' . $mailboxId,
			'ACTION_TYPE' => self::ACTION_TYPE,
			'WHEN_MAIL_RECEIVED' => 'Y',
			'WHEN_MANUALLY_RUN' => 'Y',
		];
		try
		{
			$addedId = $lockFilters
				? Application::getConnection()->add(MailFilterTable::getTableName(), $fields + ['ACTIVE' => 'Y'])
				: \CMailFilter::add($fields);
		}
		catch (SqlQueryException)
		{
			$addedId = false;
		}

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

	public static function fetchFilters(int $mailboxId, bool $lockFilters = false): array
	{
		if ($lockFilters)
		{
			$connection = Application::getConnection();
			return $connection->query(sprintf(
				"SELECT ID, ACTIVE, WHEN_MAIL_RECEIVED FROM %s WHERE MAILBOX_ID = %u"
					. " AND ACTION_TYPE = 'crm_imap' ORDER BY ID%s",
				SqlRowLock::table(MailFilterTable::getTableName(), $connection->getType()),
				$mailboxId,
				SqlRowLock::suffix($connection->getType()),
			))->fetchAll();
		}

		return MailFilterTable::getList([
			'select' => ['ID', 'ACTIVE', 'WHEN_MAIL_RECEIVED'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ACTION_TYPE' => self::ACTION_TYPE,
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	private static function deleteFilters(array $ids, bool $deferEffects): bool
	{
		foreach ($ids as $id)
		{
			if ($deferEffects)
			{
				$connection = Application::getConnection();
				$connection->queryExecute(sprintf('DELETE FROM b_mail_filter_cond WHERE FILTER_ID = %u', $id));
				$connection->queryExecute(sprintf('DELETE FROM %s WHERE ID = %u', MailFilterTable::getTableName(), $id));
			}
			elseif (!\CMailFilter::delete($id))
			{
				return false;
			}
		}

		return true;
	}
}
