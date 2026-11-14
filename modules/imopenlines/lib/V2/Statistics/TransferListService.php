<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Model\OperatorTransferTable;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\V2\Statistics\Dto\TransferItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Chronological transfer history for a set of sessions for imopenlines.v2.Session.Transfer.list.
 */
final class TransferListService
{
	public const MAX_BATCH = 50;

	public const ERROR_BATCH_LIMIT_EXCEEDED = 'BATCH_LIMIT_EXCEEDED';
	public const ERROR_INVALID_ARGUMENT = 'INVALID_ARGUMENT';

	public function getList(int $userId, array $sessionIds): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		$sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));

		if (empty($sessionIds))
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_TRANSFER_LIST_ID_REQUIRED'), self::ERROR_INVALID_ARGUMENT));

			return $result;
		}

		if (count($sessionIds) > self::MAX_BATCH)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_TRANSFER_LIST_BATCH_LIMIT_EXCEEDED', ['#MAX#' => self::MAX_BATCH]),
				self::ERROR_BATCH_LIMIT_EXCEEDED
			));

			return $result;
		}

		$allowedOperatorIds = AllowedOperators::getAllowedOperatorIds($userId);
		$visibleSessionIds = $this->loadVisibleSessionIds($sessionIds, $allowedOperatorIds);

		if (empty($visibleSessionIds))
		{
			$result->setData(['transfers' => []]);

			return $result;
		}

		$rows = OperatorTransferTable::getList([
			'select' => [
				'SESSION_ID',
				'CONFIG_ID',
				'USER_ID',
				'TRANSFER_MODE',
				'TRANSFER_TYPE',
				'TRANSFER_USER_ID',
				'TRANSFER_LINE_ID',
				'DATE_CREATE',
			],
			'filter' => ['=SESSION_ID' => $visibleSessionIds],
			'order' => ['DATE_CREATE' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$items = [];
		foreach ($rows as $row)
		{
			// Dictionary::getTransferReason() needs the session's line AT THE TIME OF
			// THE TRANSFER to tell a same-line requeue from a cross-line transfer; the
			// transfer row's own CONFIG_ID column already captures exactly that (it is
			// NOT the session's current/present-day CONFIG_ID, which may have changed
			// since - see TransferItem doc).
			$items[] = (new TransferItem($row, (int)$row['CONFIG_ID']))->toRestFormat();
		}

		$result->setData(['transfers' => $items]);

		return $result;
	}

	/**
	 * @return int[] Session IDs (subset of the requested ones) visible to the current user.
	 */
	private function loadVisibleSessionIds(array $sessionIds, ?array $allowedOperatorIds): array
	{
		$filter = ['=ID' => $sessionIds];

		if (is_array($allowedOperatorIds))
		{
			if (empty($allowedOperatorIds))
			{
				return [];
			}

			$filter['=OPERATOR_ID'] = $allowedOperatorIds;
		}

		$rows = SessionTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
		])->fetchAll();

		return array_map(static fn(array $row) => (int)$row['ID'], $rows);
	}
}
