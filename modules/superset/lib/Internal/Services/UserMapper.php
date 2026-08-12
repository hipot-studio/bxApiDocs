<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalUserRepository;

class UserMapper
{
	public static function mapUsersToClientIds(array $elements, Server $server): array
	{
		$result = $elements;
		$owners = [];

		foreach ($elements as $element)
		{
			foreach (($element['owners'] ?? []) as $owner)
			{
				if (
					is_array($owner)
					&& isset($owner['id'])
					&& !in_array((int)$owner['id'], $owners, true)
				)
				{
					$owners[] = (int)$owner['id'];
				}
			}
		}

		if (empty($owners))
		{
			return $result;
		}

		$clientIds = (new LocalUserRepository())->mapClientIdsByExternalIds($server, $owners);

		foreach ($result as $elementKey => $element)
		{
			foreach (($element['owners'] ?? []) as $ownerKey => $owner)
			{
				if (!is_array($owner) || !isset($owner['id']))
				{
					continue;
				}

				$ownerId = (int)$owner['id'];
				$result[$elementKey]['owners'][$ownerKey] = $clientIds[$ownerId] ?? null;
			}
		}

		return $result;
	}
}
