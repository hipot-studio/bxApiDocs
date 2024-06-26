<?php

namespace Bitrix\Tasks\Internals\Counter\State;

use Bitrix\Main\Config\Option;
use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\Internals\Counter\CounterState;

class Factory
{
	private static $instance;

	public static function getState(int $userId): CounterState
	{
		if (
			!self::$instance
			|| !array_key_exists($userId, self::$instance)
		)
		{
			$loader = new Counter\Loader($userId);

			if (self::useInMemoryState())
			{
				self::$instance[$userId] = new InMemory($userId, $loader);
			}
			elseif ($loader->getTotalCounters() >= Counter::getGlobalLimit())
			{
				// use db directly
				self::$instance[$userId] = new InDatabase($userId, $loader);
			}
			else
			{
				// by default
				self::$instance[$userId] = new InMemory($userId, $loader);
			}
		}

		return self::$instance[$userId];
	}

	public static function reloadState(int $userId)
	{
		if (
			self::$instance
			&& array_key_exists($userId, self::$instance)
		)
		{
			$state = self::$instance[$userId];
			$state->init();
		}
	}

	private static function useInMemoryState(): bool
	{
		return true;

		if (Option::get('tasks', 'tasks_use_in_memory_counter_state', 'null') !== 'null')
		{
			return true;
		}

		return false;
	}
}