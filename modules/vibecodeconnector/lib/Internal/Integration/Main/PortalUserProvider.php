<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\UserTable;

class PortalUserProvider
{
	public function getEmail(int $userId): ?string
	{
		if ($userId <= 0)
		{
			return null;
		}

		$row = UserTable::getRow([
			'select' => [
				'EMAIL',
			],
			'filter' => [
				'=ID' => $userId,
			],
		]);

		$email = trim((string)($row['EMAIL'] ?? ''));

		return $email !== '' ? $email : null;
	}
}
