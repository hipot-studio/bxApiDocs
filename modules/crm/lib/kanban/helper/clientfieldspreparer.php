<?php

namespace Bitrix\Crm\Kanban\Helper;

use Bitrix\Crm\Component\EntityList\ClientDataProvider\KanbanDataProvider;
use Bitrix\Main\Localization\Loc;

final class ClientFieldsPreparer
{
	public function __construct(
		private readonly ?KanbanDataProvider $contactDataProvider = null,
		private readonly ?KanbanDataProvider $companyDataProvider = null,
		private readonly ?int $priorityEntityTypeId = null,
	)
	{
	}

	/**
	 * @internal
	 */
	public function prepareFields(array &$fields): void
	{
		foreach ($fields as $i => $field)
		{
			if (!isset($field['NAME']) || !is_string($field['NAME']))
			{
				continue;
			}

			if (
				$this->contactDataProvider !== null
				&& str_starts_with($field['NAME'], 'CONTACT_')
			)
			{
				unset($fields[$i]);
			}
			elseif (
				$this->companyDataProvider !== null
				&& str_starts_with($field['NAME'], 'COMPANY_')
			)
			{
				unset($fields[$i]);
			}
		}
	}

	public function normalizeFilter(array $filter): array
	{
		if ($this->contactDataProvider !== null)
		{
			$filter = $this->contactDataProvider->normalizeFilter($filter);
		}
		if ($this->companyDataProvider !== null)
		{
			$filter = $this->companyDataProvider->normalizeFilter($filter);
		}

		return $filter;
	}

	/**
	 * @internal
	 */
	public function getClientFields(): array
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
			$firstProvider?->getPopupFields() ?? [],
			$secondProvider?->getPopupFields() ?? [],
		);
	}

	/**
	 * @internal
	 */
	public function getClientFieldsSections(): array
	{
		$contactSection = $this->getContactFieldsSection();
		$companySection = $this->getCompanyFieldsSection();

		$clientSections =
			$this->priorityEntityTypeId === \CCrmOwnerType::Contact
				? [$contactSection, $companySection]
				: [$companySection, $contactSection]
		;

		return array_values(array_filter($clientSections));
	}

	private function getContactFieldsSection(): ?array
	{
		if ($this->contactDataProvider === null)
		{
			return null;
		}

		return [
			'name' => 'contact_fields',
			'title' => Loc::getMessage('CRM_KANBAN_FIELD_SECTION_CONTACTS'),
			'type' => 'section',
			'elementsRule' => '/^CONTACT\_/',
			'viewTypes' => ['view'],
		];
	}

	private function getCompanyFieldsSection(): ?array
	{
		if ($this->companyDataProvider === null)
		{
			return null;
		}

		return [
			'name' => 'company_fields',
			'title' => Loc::getMessage('CRM_KANBAN_FIELD_SECTION_COMPANIES'),
			'type' => 'section',
			'elementsRule' => '/^COMPANY\_/',
			'viewTypes' => ['view'],
		];
	}
}
