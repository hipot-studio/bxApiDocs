<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Event;

use Bitrix\Im\V2\Common\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;

abstract class BaseLegacyEvent implements Event
{
	use ResultTrait;

	protected string $type;
	protected array $parameters;
	/**
	 * @var EventResult[]
	 */
	protected array $results = [];

	public function __construct(string $type, array $parameters = [])
	{
		$this->type = $type;
		$this->parameters = $parameters;
	}

	public function send(mixed $sender = null): void
	{
		foreach(EventManager::getInstance()->findEventHandlers('im', $this->type) as $event)
		{
			$result = ExecuteModuleEventEx($event, [$this->parameters]);
			$this->addToResult($result);
		}
	}

	public function getResults(): array
	{
		return $this->results;
	}

	public function isCancelled(): bool
	{
		foreach ($this->results as $result)
		{
			if ($result->getParameters() === false)
			{
				return true;
			}
		}

		return false;
	}

	private function addToResult(mixed $result): void
	{
		if ($result === null)
		{
			return;
		}
		if ($result instanceof EventResult)
		{
			$preparedResult = $result;
		}
		else
		{
			$preparedResult = new EventResult(EventResult::UNDEFINED, $result);
		}

		$this->results[] = $preparedResult;
	}
}
