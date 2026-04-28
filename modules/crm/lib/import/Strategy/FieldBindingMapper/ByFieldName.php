<?php

namespace Bitrix\Crm\Import\Strategy\FieldBindingMapper;

use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface\CanConfigureFieldBindingMap;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Closure;

final class ByFieldName implements FieldBindingMapperInterface
{
	/**
	 * @param FieldCollection $fieldCollection
	 * @param (Closure(ImportEntityFieldInterface): array<string>)|null $getAliasesCallback
	 */
	public function __construct(
		private readonly FieldCollection $fieldCollection,
		private readonly ?Closure $getAliasesCallback = null,
	)
	{
	}

	public function map(ReaderInterface $reader): FieldBindings
	{
		$fieldBindings = new FieldBindings();

		$map = [];
		foreach ($this->fieldCollection->getAll() as $field)
		{
			if ($field instanceof CanConfigureFieldBindingMap && $field->isFieldBindingMapEnabled() === false)
			{
				continue;
			}

			$fieldCaptions = [
				$field->getCaption(),
			];

			if (is_callable($this->getAliasesCallback))
			{
				$fieldCaptions = [
					...$fieldCaptions,
					...($this->getAliasesCallback)($field),
				];
			}

			foreach ($fieldCaptions as $fieldCaption)
			{
				$map[mb_strtolower($fieldCaption)] = $field->getId();
			}
		}

		foreach ($reader->getHeaders() as $fileHeader)
		{
			$fieldId = $map[mb_strtolower($fileHeader->getTitle())] ?? null;
			if ($fieldId === null)
			{
				continue;
			}

			$binding = new FieldBindings\Binding(
				$fieldId,
				$fileHeader->getColumnIndex(),
			);

			$fieldBindings->set($binding);
		}

		return $fieldBindings;
	}
}
