<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\Authentication\ApplicationPasswordTable;

class ApplicationPasswordService
{
	public function removeAllByUserId(int $userId): void
	{
		$passwordsList = ApplicationPasswordTable::getList([
			"filter" => [
				"=USER_ID" => $userId,
				"=APPLICATION_ID" => ["desktop", "mobile"],
			],
		]);

		while ($password = $passwordsList->fetchObject())
		{
			$password->delete();
		}
	}
}
