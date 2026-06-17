<?php

namespace Bitrix\Crm\Component\EntityList\ClientField;

use Bitrix\Crm\Component\EntityList\ClientDataProvider\GridDataProvider;

final class ClientFieldsPreparer
{
	public function __construct(
		private readonly ?GridDataProvider $contactDataProvider = null,
		private readonly ?GridDataProvider $companyDataProvider = null,
		private readonly ?int $priorityEntityTypeId = null,
	)
	{
	}

	/**
	 * @internal
	 */
	public function getHeaders(): array
	{
		if ($this->priorityEntityTypeId === \CCrmOwnerType::Contact)
		{
			$firstProvider = $this->contactDataProvider;
			$secondProvider = $this->companyDataProvider;
		}
		else
		{
			$firstProvider = $this->companyDataProvider;
			$secondProvider = $this->contactDataProvider;
		}

		return array_merge(
			$firstProvider?->getHeaders() ?? [],
			$secondProvider?->getHeaders() ?? [],
		);
	}

	/**
	 * @internal
	 */
	public function getDisplayFields(): array
	{
		return array_merge(
			$this->contactDataProvider?->getDisplayFields() ?? [],
			$this->companyDataProvider?->getDisplayFields() ?? [],
		);
	}

	/**
	 * @internal
	 */
	public function prepareSelect(array &$select): void
	{
		$this->contactDataProvider?->prepareSelect($select);
		$this->companyDataProvider?->prepareSelect($select);
	}

	/**
	 * @internal
	 */
	public function isFieldExists(string $fieldId): bool
	{
		return
			$this->contactDataProvider?->isFieldExists($fieldId)
			|| $this->companyDataProvider?->isFieldExists($fieldId)
		;
	}

	/**
	 * @internal
	 */
	public function normalizeField(string $fieldId): string
	{
		return
			$this->contactDataProvider?->normalizeField($fieldId)
			?? $this->companyDataProvider?->normalizeField($fieldId)
			?? $fieldId
		;
	}

	/**
	 * @internal
	 */
	public function appendResult(array &$items): void
	{
		$this->contactDataProvider?->appendResult($items);
		$this->companyDataProvider?->appendResult($items);
	}
}
