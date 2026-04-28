<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\Authentication\ApplicationPasswordTable;

class ApplicationPasswordService
{
	public function removeAllByUserId(int $userId): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => ["desktop", "mobile"],
		]);
	}

	public function removeAllByUserIdExceptDevice(int $userId, string $deviceCode): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => ["desktop", "mobile"],
			"!=CODE" => $deviceCode,
		]);
	}

	private function deleteByFilter(array $filter): void
	{
		$passwordsList = ApplicationPasswordTable::getList([
			"filter" => $filter,
		]);

		while ($password = $passwordsList->fetchObject())
		{
			$password->delete();
		}
	}
}
