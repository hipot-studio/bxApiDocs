<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Main\DB\Connection;

/**
 * Makes the check of the limit of private rules and the insert of a new rule one step: two
 * administrators creating rules at once would both pass the check otherwise.
 */
class FormatPolicyRuleCreationLock
{
	private const LOCK_NAME = 'disk.format_policy_rule.create';
	private const LOCK_TIMEOUT = 5;

	public function __construct(private readonly Connection $connection)
	{
	}

	public function acquire(): bool
	{
		return $this->connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
	}

	public function release(): void
	{
		$this->connection->unlock(self::LOCK_NAME);
	}
}
