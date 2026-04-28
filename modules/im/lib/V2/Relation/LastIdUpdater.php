<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Relation;

use Bitrix\Main\Application;

class LastIdUpdater
{
	public function updateAll(int $userId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute($helper->prepareCorrelatedUpdate(
			'b_im_relation',
			'R',
			[
				'LAST_ID' => 'C.LAST_MESSAGE_ID',
			],
			' b_im_chat C ',
			" C.ID = R.CHAT_ID"
			. " AND R.MESSAGE_TYPE NOT IN ('" . \IM_MESSAGE_OPEN_LINE . "', '" . \IM_MESSAGE_SYSTEM . "')"
			. " AND R.USER_ID = {$userId}"
		));

		ChatRelations::cleanAllCache();
	}

	public function updateByChatIds(array $chatIds, int $userId): void
	{
		if (empty($chatIds))
		{
			return;
		}

		$chatIdsString = implode(',', array_map('intval', $chatIds));
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute($helper->prepareCorrelatedUpdate(
			'b_im_relation',
			'R',
			[
				'LAST_ID' => 'C.LAST_MESSAGE_ID',
			],
			' b_im_chat C ',
			" C.ID = R.CHAT_ID AND R.CHAT_ID IN ({$chatIdsString}) AND R.USER_ID = {$userId}"
		));

		ChatRelations::cleanAllCache();
	}
}
