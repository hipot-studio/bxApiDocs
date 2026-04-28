<?php

namespace Bitrix\Crm\Integration\BizProc\Starter;

use Bitrix\Main\Loader;
use CBPHelper;

final class DocumentFieldComparator
{
	private array $actual;
	private array $previous;
	private int $entityTypeId;

	public function __construct(int $entityTypeId, array $actual, array $previous)
	{
		$this->actual = $actual;
		$this->previous = $previous;
		$this->entityTypeId = $entityTypeId;
	}

	public function compare(): array
	{
		$diff = [];

		$fields = $this->getDocumentFields();

		foreach ($this->actual as $key => $field)
		{
			$property = $fields[$key] ?? [];
			$type = $property['Type'] ?? 'string';

			if (
				$key !== 'ID'
				&& (!array_key_exists($key, $this->previous) || $this->isDifferent($this->previous[$key], $field, (string)$type))
			)
			{
				if (!$this->isDefaultValue($key, $field) || !$this->isEmptyValue($this->previous[$key] ?? null))
				{
					$diff[] = $key;
				}
			}
		}

		return $diff;
	}

	private function isDifferent(mixed $value1, mixed $value2, string $type): bool
	{
		// case: [] vs ['']
		if ($this->isEmptyValue($value1) && $this->isEmptyValue($value2))
		{
			return false;
		}

		// case: 'N' vs '0'
		if ($type === 'bool')
		{
			return !($this->getBool($value1) === $this->getBool($value2));
		}

		// '1' and 1 are considered equal (type coercion)
		return $value1 != $value2;
	}

	private function isDefaultValue(string $fieldName, $fieldValue): bool
	{
		$fields = $this->getDocumentFields();

		return (isset($fields[$fieldName]['Default']) && $fields[$fieldName]['Default'] === $fieldValue);
	}

	private function isEmptyValue(mixed $value): bool
	{
		return $this->loadBizproc() && CBPHelper::isEmptyValue($value);
	}

	private function getBool(mixed $value): bool
	{
		return $this->loadBizproc() && CBPHelper::getBool($value);
	}

	private function getDocumentFields(): array
	{
		if (!$this->loadBizproc())
		{
			return [];
		}

		static $documentFields = [];
		if (!isset($documentFields[$this->entityTypeId]))
		{
			$fields = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentFields($this->getDocumentType());
			$documentFields[$this->entityTypeId] = is_array($fields) ? $fields : [];
		}

		return $documentFields[$this->entityTypeId];
	}

	private function getDocumentType(): ?array
	{
		return \CCrmBizProcHelper::ResolveDocumentType($this->entityTypeId);
	}

	private function loadBizproc(): bool
	{
		return Loader::includeModule('bizproc');
	}
}
