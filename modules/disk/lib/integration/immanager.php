<?php

namespace Bitrix\Disk\Integration;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SystemException;

final class ImManager
{
	/**
	 * Answers which of the given disk objects are already published as chat message files.
	 *
	 * @param array<int, int> $objectIds
	 * @return array<int, true>
	 * @throws SystemException When im is installed but could not be loaded. Callers use the
	 *                         answer to prove an upload is orphaned, so an empty list must
	 *                         never stand in for an unavailable one.
	 */
	public function getFileIdsPublishedInChats(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));
		if ($objectIds === [])
		{
			return [];
		}

		if (!ModuleManager::isModuleInstalled('im'))
		{
			return [];
		}

		if (!Loader::includeModule('im'))
		{
			throw new SystemException('Module im is installed but could not be loaded.');
		}

		$rows = \Bitrix\Im\Model\MessageParamTable::query()
			->setSelect(['PARAM_VALUE'])
			->where('PARAM_NAME', 'FILE_ID')
			->whereIn('PARAM_VALUE', array_map('strval', $objectIds))
			->setDistinct()
			->exec()
		;

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[(int)$row['PARAM_VALUE']] = true;
		}

		return $result;
	}

	public function sendMessageToGeneralChat(int $fromUserId, array $params): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$chatId = \CIMChat::GetGeneralChatId();
		if (!$chatId)
		{
			return false;
		}

		$params = array_merge(
			$params,
			[
				'TO_CHAT_ID' => $chatId,
				'FROM_USER_ID' => $fromUserId,
				'SKIP_USER_CHECK' => 'Y',
			]
		);

		$result = \CIMChat::AddMessage($params);

		return $result !== false;
	}
}