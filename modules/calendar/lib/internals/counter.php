<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Calendar\Internals\Counter\CounterDictionary;
use Bitrix\Calendar\Internals\Counter\Provider\Invite;
use Bitrix\Calendar\Internals\Counter\Provider\Sync;

/**
 * Class Counter
 *
 * @package Bitrix\Calendar\Internals
 */
class Counter
{
	private static $instance = [];
	private int $userId;

	/**
	 * @param $userId
	 * @return static
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function getInstance($userId): self
	{
		if (!array_key_exists($userId, self::$instance))
		{
			self::$instance[$userId] = new self($userId);
		}

		return self::$instance[$userId];
	}

	/**
	 * Counter constructor.
	 *
	 * @param $userId
	 * @param int $groupId
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	private function __construct($userId)
	{
		$this->userId = (int)$userId;
	}

	/**
	 * @param string $name
	 * @param int $entityId
	 * @return int
	 */
	public function get(string $name, int $entityId = 0): int
	{
		$value = 0;

		switch ($name)
		{
			case CounterDictionary::COUNTER_TOTAL:
				$value = $this->get(CounterDictionary::COUNTER_INVITES, $entityId)
					+ $this->get(CounterDictionary::COUNTER_SYNC_ERRORS, $entityId);
				break;
			case CounterDictionary::COUNTER_INVITES:
				$value = (new Invite($this->userId, $entityId))->getValue();
				break;
			case CounterDictionary::COUNTER_SYNC_ERRORS:
				$value = (new Sync($this->userId, $entityId))->getValue();
				break;
		}

		return $value;
	}
}