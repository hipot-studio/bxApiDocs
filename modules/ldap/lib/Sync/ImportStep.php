<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Ldap\Cursor;
use Bitrix\Ldap\Internal\Entry;
use Bitrix\Ldap\Internal\Models\UserLastSyncTable;
use Bitrix\Ldap\Internal\User\Provider;
use Bitrix\Ldap\Limit;
use Bitrix\Main;

class ImportStep
{
	protected string $cookie = '';

	protected array $departmentCache = [];

	protected bool $forceUpdate = false;

	protected ?string $lastError = null;

	protected int $addedCount = 0;

	protected int $updatedCount = 0;

	public function getCookie(): string
	{
		return $this->cookie;
	}

	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	public function getAddedCount(): int
	{
		return $this->addedCount;
	}

	public function getUpdatedCount(): int
	{
		return $this->updatedCount;
	}

	public function execute(\CLDAP $connection, Session $session, string $dn, string $cookie): void
	{
		$this->cookie = $cookie;
		$this->departmentCache = [];
		$this->lastError = null;
		$this->addedCount = 0;
		$this->updatedCount = 0;

		$page = $connection->getUserListPaginated(
			baseDn: $dn,
			cursor: new Cursor(cookie: $cookie),
		);

		$this->cookie = $page->getCookie();

		$ldapUsers = [];
		$lastSyncTs = $connection->getLastSyncTs();
		$ldapLoginAttr = mb_strtolower($connection->getLdapLoginAttr());

		/** @var Entry $ldapUserEntry */
		foreach ($page->getEntries() as $ldapUserEntry)
		{
			$login = mb_strtolower($ldapUserEntry->getAttribute($ldapLoginAttr));
			$ldapUsers[$login] = $ldapUserEntry->toArray();
		}

		$bitrixUsers = Provider::getByLogins(array_keys($ldapUsers));

		foreach ($ldapUsers as $login => $ldapUser)
		{
			if (!isset($bitrixUsers[$login]))
			{
				if (!$connection->isUserCreationAllowed())
				{
					continue;
				}

				$userActive = $connection->getLdapValueByBitrixFieldName('ACTIVE', $ldapUser);

				if ($userActive !== 'Y')
				{
					continue;
				}

				$userFields = $connection->GetUserFields($ldapUser, $this->departmentCache);

				if (\CLdapServer::isUserInBannedGroups($session->serverId, $userFields))
				{
					continue;
				}

				if ($userId = $connection->SetUser($userFields, true))
				{
					$this->addedCount++;
					$this->markSynced((int)$userId, $session);
				}
				else if (Limit::isUserLimitExceeded())
				{
					$this->lastError = Limit::getUserLimitNotifyMessage();
					break;
				}
			}
			else
			{
				// update
				$updatedAtLdap = $this->getUserLastUpdateTsLdap($connection, $ldapUser);
				$updatedAtBitrix = $this->getUserLastUpdateTsBitrix($bitrixUsers[$login]);

				$userWasNotModifiedSinceLastSync = (
					$lastSyncTs > 0
					&& $lastSyncTs >= $updatedAtLdap
					&& $lastSyncTs >= $updatedAtBitrix
				);

				if ($userWasNotModifiedSinceLastSync && !$this->forceUpdate)
				{
					$this->markSynced((int)$bitrixUsers[$login]['ID'], $session);

					continue;
				}

				$userFields = $connection->GetUserFields($ldapUser, $this->departmentCache);

				if (\CLdapServer::isUserInBannedGroups($session->serverId, $userFields))
				{
					$this->markSynced((int)$bitrixUsers[$login]['ID'], $session);

					continue;
				}

				$userFields['ID'] = $bitrixUsers[$login]['ID'];

				if ($connection->SetUser($userFields, true))
				{
					$this->updatedCount++;
					$this->markSynced((int)$bitrixUsers[$login]['ID'], $session);
				}
				else if (Limit::isUserLimitExceeded())
				{
					$this->lastError = Limit::getUserLimitNotifyMessage();
					break;
				}
			}

			$this->checkLastSavedUserError($login);
		}
	}

	protected function markSynced(int $userId, Session $session): void
	{
		if ($userId > 0)
		{
			UserLastSyncTable::add([
				'USER_ID' => $userId,
				'SERVER_ID' => $session->serverId,
				'SESSION_ID' => $session->id,
				'LAST_SYNC_AT' => new Main\Type\DateTime(),
			]);
		}
	}

	protected function getUserLastUpdateTsLdap(\CLDAP $connection, array $ldapUser): int
	{
		$whenChangedAttr = mb_strtolower($connection->getLdapUserWhenChangedAttr());

		if ($whenChangedAttr === '')
		{
			return time();
		}

		$pattern = "'([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\.0Z'";
		$date = isset($ldapUser[$whenChangedAttr]) ? (string)$ldapUser[$whenChangedAttr] : '';
		$matches = [];

		if (!preg_match($pattern, $date, $matches))
		{
			return time();
		}

		$ldapTime = gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);

		if (!$ldapTime)
		{
			return time();
		}

		return $ldapTime;
	}

	protected function getUserLastUpdateTsBitrix(array $bitrixUser): int
	{
		$bitrixTime = MakeTimeStamp($bitrixUser['TIMESTAMP_X']);
		if (!$bitrixTime)
		{
			return time();
		}

		return $bitrixTime;
	}

	protected function checkLastSavedUserError(string $login): void
	{
		global $USER;

		if ($USER && $USER->LAST_ERROR !== '')
		{
			$msg = sprintf('Cannot save user %s: %s', $login, $USER->LAST_ERROR);
			$USER->LAST_ERROR = '';

			$this->lastError = $msg;
		}
	}
}
