<?php

namespace Bitrix\Crm\Import\Strategy\FieldBindingMapper;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByRegexp\Rule;

final class ByRegexp implements FieldBindingMapperInterface
{
	public function __construct(
		/** @var Rule[] */
		private readonly array $rules,
	)
	{
	}

	public function map(ReaderInterface $reader): FieldBindings
	{
		$fieldBindings = new FieldBindings();
		foreach ($reader->getHeaders() as $header)
		{
			foreach ($this->rules as $rule)
			{
				$fieldId = $rule->match($header);
				if ($fieldId !== null)
				{
					$fieldBindings->set(
						new FieldBindings\Binding(
							fieldId: $fieldId,
							columnIndex: $header->getColumnIndex(),
						),
					);

					break;
				}
			}
		}

		return $fieldBindings;
	}
}
