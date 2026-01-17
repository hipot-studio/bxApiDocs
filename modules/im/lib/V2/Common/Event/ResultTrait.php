<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Event;

use Bitrix\Main\EventResult;

trait ResultTrait
{
	/**
	 * @return EventResult[]
	 */
	abstract public function getResults();

	private function getFirstResult(): ?EventResult
	{
		return array_values($this->getResults())[0] ?? null;
	}

	public function hasResult(): bool
	{
		return $this->getFirstResult() !== null;
	}

	protected function getParameterFromResult(string $key): mixed
	{
		$parameters = $this->getFirstResult()?->getParameters() ?? [];
		if (!is_array($parameters))
		{
			return null;
		}

		return $parameters[$key] ?? null;
	}
}
