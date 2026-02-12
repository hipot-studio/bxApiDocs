<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeActionDictionary implements Arrayable, \JsonSerializable
{
	private array $map = [];
	
	public function __construct(NodeAction ...$actionList)
	{
		foreach ($actionList as $action)
		{
			$this->map[$action->activityCode] = $action;
		}
	}

	public function add(NodeAction $action): self
	{
		$this->map[$action->activityCode] = $action;

		return $this;
	}

	public function get(string $activityId): ?NodeAction
	{
		return $this->map[$activityId] ?? null;
	}

	public function isEmpty(): bool
	{
		return empty($this->map);
	}

	public function toArray(): array
	{
		return $this->map;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
