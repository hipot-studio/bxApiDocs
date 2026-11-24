<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;

final class SupersetOwnership
{
	private array $clientIds = [];

	public static function isOwner(int $userId, array $owners): bool
	{
		return (new self())->check($userId, $owners);
	}

	public function check(int $userId, array $owners): bool
	{
		if (!array_key_exists($userId, $this->clientIds))
		{
			$row = SupersetUserTable::getRow([
				'select' => ['CLIENT_ID'],
				'filter' => ['=USER_ID' => $userId],
			]);
			$this->clientIds[$userId] = (string)($row['CLIENT_ID'] ?? '');
		}
		$clientId = $this->clientIds[$userId];
		if ($clientId === '')
		{
			return false;
		}

		foreach ($owners as $owner)
		{
			if (is_string($owner) && $owner !== '' && $owner === $clientId)
			{
				return true;
			}
		}

		return false;
	}
}
