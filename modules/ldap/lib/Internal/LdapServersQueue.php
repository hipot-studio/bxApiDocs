<?php

namespace Bitrix\Ldap\Internal;

use CLDAP;
use CLdapServer;
use CUser;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 * Prepares a queue of AD-servers, considering EXTERNAL_AUTH_ID field from b_user.
 * Preferred server (specified in EXTERNAL_AUTH_ID) will go first.
 */
final class LdapServersQueue
{
	public readonly string $login;
	public readonly string $prefix;
	public array $filter = [];

	/** @var CLDAP[]|null */
	private ?array $queue = null;

	public function __construct(string $login, string $prefix = '')
	{
		$this->login = $login;
		$this->prefix = $prefix;
		$this->filter = ['ACTIVE' => 'Y'];
		if ($prefix !== '')
		{
			$this->filter['CODE'] = $prefix;
		}
	}

	public function getNextServer(): ?CLDAP
	{
		if ($this->queue === null)
		{
			/** @var array<int, CLDAP> $servers */
			$servers = [];
			$dbServers = CLdapServer::GetList([], $this->filter);
			while ($server = $dbServers->GetNextServer())
			{
				/** @var CLDAP $server */
				$servers[(int)$server->arFields['ID']] = $server;
			}

			if (count($servers) > 1)
			{
				$preferredServerId = $this->getPreferredServerId();
				if ($preferredServerId > 0 && isset($servers[$preferredServerId]))
				{
					$servers = [ $preferredServerId => $servers[$preferredServerId] ] + $servers;
				}
			}

			$this->queue = $servers;
			reset($this->queue);
		}

		$result = current($this->queue);
		next($this->queue);
		return $result === false ? null : $result;
	}

	private function getPreferredServerId(): ?int
	{
		/** @var array<string, int|null> $cache */
		static $cache = [];
		if (array_key_exists($this->login, $cache))
		{
			return $cache[$this->login];
		}

		$select = ['FIELDS' => ['EXTERNAL_AUTH_ID']];
		$filter = ['LOGIN_EQUAL_EXACT' => $this->login];
		$sortField = 'id';
		$sortDirection = 'asc';

		$dbUser = CUser::GetList($sortField, $sortDirection, $filter, $select);

		if ($user = $dbUser->Fetch())
		{
			$matches = [];
			if (preg_match('/^LDAP#(\d+)$/', $user['EXTERNAL_AUTH_ID'], $matches))
			{
				$cache[$this->login] = (int)$matches[1];
				return (int)$matches[1];
			}
		}

		$cache[$this->login] = null;

		return null;
	}
}
