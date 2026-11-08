<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Dto;

use Bitrix\Bizproc\Workflow\Template\SourceType;

final class ProcessDataCatalog
{
	/** @var ProcessDataElement[] */
	private readonly array $elements;

	/** @var array<string, array<string, ProcessDataElement>>|null Built on the first search. */
	private ?array $elementsBySourceType = null;

	/**
	 * @param ProcessDataElement[] $elements Elements in block order.
	 */
	public function __construct(array $elements = [])
	{
		$this->elements = array_values($elements);
	}

	/**
	 * @return ProcessDataElement[]
	 */
	public function getElements(): array
	{
		return $this->elements;
	}

	public function findByKey(string $key, string $sourceType): ?ProcessDataElement
	{
		return $this->elementsBySourceType()[$sourceType][mb_strtolower($key)] ?? null;
	}

	/**
	 * Elements by source type and lowered key, the first of the same key standing, exactly as a walk over the
	 * catalog answered. The map is built once: the elements never change. The half of a pair stands under the
	 * parameter type as well, a start value answering to a parameter reference.
	 *
	 * @return array<string, array<string, ProcessDataElement>>
	 */
	private function elementsBySourceType(): array
	{
		if ($this->elementsBySourceType === null)
		{
			$bySourceType = [];
			foreach ($this->elements as $element)
			{
				$loweredKey = mb_strtolower($element->key);
				$bySourceType[$element->sourceType][$loweredKey] ??= $element;

				if ($element->acceptsStartValue)
				{
					$bySourceType[SourceType::Parameter][$loweredKey] ??= $element;
				}
			}

			$this->elementsBySourceType = $bySourceType;
		}

		return $this->elementsBySourceType;
	}
}
