<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Admin;

use Bitrix\Main\UserTable;

final class PreviewUsersProvider
{
	public function getRecent(int $limit = 30): array
	{
		$rows = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN', 'EMAIL', 'LAST_LOGIN'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=EXTERNAL_AUTH_ID' => null,
			],
			'order' => ['LAST_LOGIN' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit,
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$result[] = [
				'id' => $id,
				'label' => $this->formatLabel($row),
			];
		}

		return $result;
	}

	private function formatLabel(array $row): string
	{
		$nameParts = array_filter([
			(string)($row['NAME'] ?? ''),
			(string)($row['LAST_NAME'] ?? ''),
		], static fn (string $part): bool => $part !== '');
		$fullName = trim(implode(' ', $nameParts));
		$login = (string)($row['LOGIN'] ?? '');
		$email = (string)($row['EMAIL'] ?? '');
		$id = (int)($row['ID'] ?? 0);

		$primary = $fullName !== '' ? $fullName : ($login !== '' ? $login : $email);
		$details = [];
		if ($login !== '' && $login !== $primary)
		{
			$details[] = $login;
		}
		if ($email !== '' && $email !== $primary && $email !== $login)
		{
			$details[] = $email;
		}

		$tail = $details !== [] ? ' [' . implode(', ', $details) . ']' : '';

		return ($primary !== '' ? $primary : 'user') . $tail . ' #' . $id;
	}
}
