<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Main\Type\DateTime;

class Session
{
	public readonly int $id;
	public readonly int $serverId;
	public readonly string $dn;
	public readonly string $cookie;
	public readonly State $state;
	public readonly DateTime $startedAt;

	public function __construct(array $fields)
	{
		$this->id = (int)$fields['ID'];
		$this->serverId = (int)$fields['SERVER_ID'];
		$this->state = State::from((string)$fields['STATE']);
		$this->startedAt = $fields['STARTED_AT'];

		$this->dn = isset($fields['PROGRESS']['DN']) ? (string)$fields['PROGRESS']['DN'] : '';

		if (isset($fields['PROGRESS']['COOKIE']))
		{
			$cookie = base64_decode((string)$fields['PROGRESS']['COOKIE']);
			$this->cookie = $cookie === false ? '' : $cookie;
		}
		else
		{
			$this->cookie = '';
		}
	}
}
