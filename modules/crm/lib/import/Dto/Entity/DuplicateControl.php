<?php

namespace Bitrix\Crm\Import\Dto\Entity;

use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlTarget;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class DuplicateControl implements Arrayable, JsonSerializable
{
	private DuplicateControlBehavior $behavior = DuplicateControlBehavior::NoControl;
	private array $targets;

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
		$this->targets = DuplicateControlTarget::getCasesForEntity($this->entityTypeId);
	}

	public function getBehavior(): DuplicateControlBehavior
	{
		return $this->behavior;
	}

	public function setBehavior(DuplicateControlBehavior $behavior): self
	{
		$this->behavior = $behavior;

		return $this;
	}

	public function getTargets(): array
	{
		return $this->targets;
	}

	/**
	 * @param DuplicateControlTarget[] $targets
	 * @return $this
	 */
	public function setTargets(array $targets): self
	{
		$this->targets = $targets;

		return $this;
	}

	public function fill(array $importSettings): self
	{
		if (isset($importSettings['duplicateControlBehavior']) && is_string($importSettings['duplicateControlBehavior']))
		{
			$behavior = DuplicateControlBehavior::tryFrom($importSettings['duplicateControlBehavior']);
			if ($behavior !== null)
			{
				$this->setBehavior($behavior);
			}
		}

		if (isset($importSettings['duplicateControlTargets']) && is_array($importSettings['duplicateControlTargets']))
		{
			$targets = [];
			foreach ($importSettings['duplicateControlTargets'] as $target)
			{
				if (is_string($target))
				{
					$target = DuplicateControlTarget::tryFrom($target);
					if ($target !== null)
					{
						$targets[] = $target;
					}
				}
			}

			$this->setTargets($targets);
		}

		return $this;
	}

	public function toArray(): array
	{
		return [
			'duplicateControlBehavior' => $this->behavior->value,
			'duplicateControlTargets' => array_map(static fn (DuplicateControlTarget $target) => $target->value, $this->targets),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
