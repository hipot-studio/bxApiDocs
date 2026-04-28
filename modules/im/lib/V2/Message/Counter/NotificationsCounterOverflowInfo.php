<?php

namespace Bitrix\Im\V2\Message\Counter;

class NotificationsCounterOverflowInfo
{
	protected array $withOverflow = [];
	protected array $withoutOverflow = [];

	public function __construct(array $withOverflow, array $withoutOverflow)
	{
		$this->withOverflow = $withOverflow;
		$this->withoutOverflow = $withoutOverflow;
	}

	/**
	 * @param CounterOverflowInfo[] $counterOverflowInfo
	 * @return self
	 */
	public static function fromCounterOverflowInfo(array $counterOverflowInfo): self
	{
		$withOverFlow = [];
		$withoutOverflow = [];
		foreach ($counterOverflowInfo as $info)
		{
			$userWithOverFlow = array_values($info->getUsersWithOverflow())[0] ?? null;
			$userWithoutOverFlow = array_values($info->getUsersWithoutOverflow())[0] ?? null;
			if ($userWithOverFlow)
			{
				$withOverFlow[$userWithOverFlow] = $info->getChatId();
			}
			if ($userWithoutOverFlow)
			{
				$withoutOverflow[$userWithoutOverFlow] = $info->getChatId();
			}
		}

		return new static($withOverFlow, $withoutOverflow);
	}

	public function getWithOverflow(): array
	{
		return $this->withOverflow;
	}

	public function getWithoutOverflow(): array
	{
		return $this->withoutOverflow;
	}

	public function hasOverflow(int $userId): bool
	{
		return isset($this->withOverflow[$userId]);
	}
}
