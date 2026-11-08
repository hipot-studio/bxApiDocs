<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Socialservices;

use Bitrix\Main\Loader;
use CSocServBitrix24Net;

final class PortalNetworkUserIds
{
	private const BATCH_SIZE = 500;

	public function getByPortalUserIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		if ($userIds === [] || !Loader::includeModule('socialservices'))
		{
			return [];
		}

		$networkIds = [];
		foreach (array_chunk($userIds, self::BATCH_SIZE) as $batch)
		{
			$rows = \Bitrix\Socialservices\UserTable::getList([
				'select' => ['ID', 'USER_ID', 'XML_ID'],
				'filter' => [
					'@USER_ID' => $batch,
					'=EXTERNAL_AUTH_ID' => CSocServBitrix24Net::ID,
				],
				'order' => ['ID' => 'ASC'],
			]);
			while ($row = $rows->fetch())
			{
				$userId = (int)$row['USER_ID'];
				if (!isset($networkIds[$userId]))
				{
					$networkIds[$userId] = (int)$row['XML_ID'];
				}
			}
		}

		return $networkIds;
	}
}
