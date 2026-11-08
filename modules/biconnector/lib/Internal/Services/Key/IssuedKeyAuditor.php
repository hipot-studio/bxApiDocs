<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Key;

use Bitrix\BIConnector\Access\Model\UserAccessItem;
use Bitrix\BIConnector\Internal\Model\UsageStatTable;
use Bitrix\BIConnector\KeyTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

/**
 * Reads the access keys issued through REST applications and revokes the suspicious ones.
 *
 * Keys without APP_ID are issued from the portal UI and stay out of the scope of this service.
 */
final class IssuedKeyAuditor
{
	public const KEY_MANAGE_OPERATION = 'biconnector_key_manage';

	public const SCOPE_NOTE = 'Keys with an empty APP_ID are issued from the portal UI and are out of the scope of this command.';

	private const SELECT = [
		'ID',
		'CREATED_BY',
		'DATE_CREATE',
		'LAST_ACTIVITY_DATE',
		'APP_ID',
		'CONNECTION',
		'ACTIVE',
	];

	/**
	 * @return array<int, array> Key rows extended with authorHasOperation and wasUsed flags.
	 */
	public function collect(): array
	{
		$rows = KeyTable::query()
			->setSelect(self::SELECT)
			->where('APP_ID', '>', 0)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		if (!$rows)
		{
			return [];
		}

		$usedKeyIds = $this->getUsedKeyIds(array_map('intval', array_column($rows, 'ID')));
		$operationByAuthor = [];

		foreach ($rows as $index => $row)
		{
			$authorId = (int)$row['CREATED_BY'];
			if (!array_key_exists($authorId, $operationByAuthor))
			{
				$operationByAuthor[$authorId] = $this->authorHasOperation($authorId);
			}

			$rows[$index]['authorHasOperation'] = $operationByAuthor[$authorId];
			$rows[$index]['wasUsed'] = isset($usedKeyIds[(int)$row['ID']]);
		}

		return $rows;
	}

	/**
	 * A row of collect() is suspicious when its author cannot manage keys right now,
	 * a dismissed or deleted author included.
	 */
	public function isSuspicious(array $row): bool
	{
		return ($row['authorHasOperation'] ?? null) === false;
	}

	/**
	 * A suspicious row that is still active and therefore waiting for the revocation. An already
	 * deactivated key is left alone, so a repeated revocation neither rewrites it nor reports it
	 * as revoked once again.
	 */
	public function isPendingRevocation(array $row): bool
	{
		return $this->isSuspicious($row) && (string)($row['ACTIVE'] ?? '') === 'Y';
	}

	/**
	 * Deactivates the given keys one by one, keeping the rows themselves.
	 *
	 * A key outside the scope of the service is refused with an error instead of being deactivated,
	 * so neither a key issued from the portal UI nor a key of an author who still manages keys
	 * can be taken down through this method. An already deactivated key is refused the same way,
	 * which keeps a repeated revocation free of a second write.
	 */
	public function revoke(array $ids): Result
	{
		$result = new Result();
		$revoked = [];
		$operationByAuthor = [];

		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id <= 0)
			{
				continue;
			}

			if (!$this->isRevokable($id, $operationByAuthor))
			{
				$result->addError(new Error(
					'Key #' . $id . ' is issued from the portal UI, is missing, is already inactive'
					. ' or its author still manages keys.',
					(string)$id,
				));

				continue;
			}

			$updateResult = KeyTable::update($id, ['ACTIVE' => 'N']);
			if ($updateResult->isSuccess())
			{
				$revoked[] = $id;

				continue;
			}

			foreach ($updateResult->getErrorMessages() as $message)
			{
				$result->addError(new Error($message, (string)$id));
			}
		}

		if ($revoked)
		{
			KeyTable::cleanCache();
		}

		return $result->setData(['revoked' => $revoked]);
	}

	/**
	 * @param array<int, bool> $operationByAuthor Cache reused across the ids of a single revoke() call.
	 */
	private function isRevokable(int $id, array &$operationByAuthor): bool
	{
		$row = KeyTable::query()
			->setSelect(['ID', 'CREATED_BY'])
			->where('ID', $id)
			->where('APP_ID', '>', 0)
			->where('ACTIVE', 'Y')
			->fetch()
		;

		if ($row === false)
		{
			return false;
		}

		$authorId = (int)$row['CREATED_BY'];
		if (!array_key_exists($authorId, $operationByAuthor))
		{
			$operationByAuthor[$authorId] = $this->authorHasOperation($authorId);
		}

		return $operationByAuthor[$authorId] === false;
	}

	/**
	 * @param int[] $keyIds
	 * @return array<int, true>
	 */
	private function getUsedKeyIds(array $keyIds): array
	{
		if (!$keyIds)
		{
			return [];
		}

		$rows = UsageStatTable::query()
			->setSelect(['KEY_ID'])
			->whereIn('KEY_ID', $keyIds)
			->setGroup(['KEY_ID'])
			->fetchAll()
		;

		$usedKeyIds = [];
		foreach ($rows as $row)
		{
			$usedKeyIds[(int)$row['KEY_ID']] = true;
		}

		return $usedKeyIds;
	}

	/**
	 * Repeats offline the gate the author would meet now. CUser::CanDoOperation() takes the operation
	 * route only for an explicit user id; the current-user route it takes inside a request lets every
	 * administrator in through IsAdmin() before the operation is ever looked up. Asking it by id alone
	 * would therefore report false for an administrator and mark every key issued through REST as
	 * suspicious, so the admin half of the same gate is asked separately.
	 */
	private function authorHasOperation(int $authorId): bool
	{
		if ($authorId <= 0 || !$this->isAuthorActive($authorId))
		{
			return false;
		}

		if (UserAccessItem::createFromId($authorId)->isAdmin())
		{
			return true;
		}

		return (bool)(new \CUser())->CanDoOperation(self::KEY_MANAGE_OPERATION, $authorId);
	}

	private function isAuthorActive(int $authorId): bool
	{
		$author = UserTable::query()
			->setSelect(['ID'])
			->where('ID', $authorId)
			->where('ACTIVE', 'Y')
			->fetch()
		;

		return $author !== false;
	}
}
