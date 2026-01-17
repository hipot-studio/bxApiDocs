<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Event;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

abstract class BaseEvent extends Event implements \Bitrix\Im\V2\Common\Event
{
	use ResultTrait;

	public function __construct(string $type, array $parameters = [])
	{
		parent::__construct('im', $type, $parameters);
	}

	public function isCancelled(): bool
	{
		foreach ($this->results as $result)
		{
			if ($result->getType() === EventResult::ERROR)
			{
				return true;
			}
		}

		return false;
	}
}
