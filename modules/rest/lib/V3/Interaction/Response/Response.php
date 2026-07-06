<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

abstract class Response implements Arrayable
{
	protected bool $showDebugInfo = true;

	protected bool $showRawData = false;

	public function toArray(): array
	{
		$reflection = new \ReflectionClass($this);
		$properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

		$result = [];
		foreach ($properties as $property)
		{
			$value = $property->getValue($this);
			if ($value instanceof Arrayable)
			{
				$value = $value->toArray();
			}
			elseif ($value instanceof DateTime)
			{
				$value = $value->format(DATE_ATOM);
			}
			elseif ($value instanceof Date)
			{
				$value = $value->format('Y-m-d');
			}
			$result[$property->getName()] = $value;
		}

		return $result;
	}

	public function isShowDebugInfo(): bool
	{
		return $this->showDebugInfo;
	}

	public function setShowDebugInfo(bool $showDebugInfo): self
	{
		$this->showDebugInfo = $showDebugInfo;

		return $this;
	}

	public function isShowRawData(): bool
	{
		return $this->showRawData;
	}

	public function setShowRawData(bool $showRawData): self
	{
		$this->showRawData = $showRawData;

		return $this;
	}
}
