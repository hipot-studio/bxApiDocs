<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity\Collection;

use Bitrix\BizprocDesigner\Internal\Entity\Port;
use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;

/**
 * @extends AbstractEntityCollection<Port>
 */
final class PortCollection extends AbstractEntityCollection
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Port;
	}

	protected function createEntityFromArray(array $data, PortDirection $portDirection = PortDirection::Output): Port
	{
		return Port::createFromArray($data, $portDirection);
	}

	public function fill(array $data): PortCollection
	{
		$this->fillFromArray(PortDirection::Input, (array)($data['input'] ?? []));
		$this->fillFromArray(PortDirection::Output, (array)($data['output'] ?? []));

		return $this;
	}

	private function fillFromArray(PortDirection $portDirection, array $data): void
	{
		foreach ($data as $element)
		{
			if (!is_array($element))
			{
				throw new \InvalidArgumentException('Expected array, got ' . gettype($element));
			}

			$this->add($this->createEntityFromArray($element, $portDirection));
		}
	}

	/**
	 * Converts the collection to an associative array with 'input' and 'output' keys.
	 *
	 * @return array<string, array>
	 */
	public function toArray(): array
	{
		$result = [
			'input' => [],
			'output' => [],
		];

		foreach ($this->items as $item)
		{
			if ($item instanceof Port)
			{
				$result[$item->direction->value][] = $item->toArray();
			}
		}

		return $result;
	}
}
