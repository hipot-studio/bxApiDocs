<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxMassconnectDTO;
use Bitrix\Mail\Internals\MailMassConnectTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Data\AddResult;

final class MailMassConnect
{
	public function create(array $massConnectData, CurrentUser $currentUser): AddResult
	{
		if (is_array($massConnectData['employees']))
		{
			foreach ($massConnectData['employees'] as &$item)
			{
				if (is_array($item))
				{
					$item['password'] = '';
				}
			}
		}

		return MailMassConnectTable::add([
			'AUTHOR_ID' => $currentUser->getId(),
			'SETTINGS_DATA' => json_encode($massConnectData),
		]);
	}

	/**
	 * Append connection result to MailMassConnectTable entity
	 *
	 * @param int $massConnectId - id of MailMassConnectTable entity
	 * @param MailboxMassconnectDTO $mailboxConnectDTO
	 * @param array $result
	 * @param array|null $errors
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function addResult(int $massConnectId, MailboxMassconnectDTO $mailboxConnectDTO, array $result, ?array $errors): void
	{
		$historyItem = MailMassConnectTable::getById($massConnectId)->fetch();

		if (!empty($errors))
		{
			if ($historyItem)
			{
				$connectionResult = json_decode($historyItem['CONNECTION_RESULT'] ?? '[]', true);
				$connectionResult['errors'] = [...($connectionResult['errors'] ?? []), $errors[0] ?? []];
				MailMassConnectTable::update($massConnectId, [
					'CONNECTION_RESULT' => json_encode($connectionResult),
				]);
			}
		}

		if (!empty($result))
		{
			if ($historyItem)
			{
				$connectionResult = json_decode($historyItem['CONNECTION_RESULT'] ?? '[]', true);
				$newResult = ['userIdToConnect' => (int)$mailboxConnectDTO->userIdToConnect, 'mailboxId' => $result['id'] ?? null];
				$connectionResult['success'] = [...($connectionResult['success'] ?? []), $newResult];
				MailMassConnectTable::update($massConnectId, [
					'CONNECTION_RESULT' => json_encode($connectionResult),
				]);
			}
		}
	}
}
