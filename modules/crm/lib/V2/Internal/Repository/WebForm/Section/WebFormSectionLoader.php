<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;

/**
 * @internal
 */
abstract class WebFormSectionLoader
{
	abstract public function getSectionName(): string;

	/**
	 * @param int[] $formIds
	 * @return array<int, mixed>
	 */
	final public function load(array $formIds, WebFormSelect $select): array
	{
		$formIds = self::normalizeFormIds($formIds);
		$selectedFields = $select->getSectionFields($this->getSectionName());
		$sectionsByFormId = [];
		foreach ($formIds as $formId)
		{
			$sectionsByFormId[$formId] = $this->createEmptySection($selectedFields);
		}

		if ($formIds === [] || !$select->hasSection($this->getSectionName()))
		{
			return $sectionsByFormId;
		}

		foreach (
			$this->loadSelected(
				$formIds,
				$selectedFields,
			) as $formId => $section
		)
		{
			if (array_key_exists($formId, $sectionsByFormId))
			{
				$sectionsByFormId[$formId] = is_array($section) ? array_values($section) : $section;
			}
		}

		return $sectionsByFormId;
	}

	/**
	 * @param string[] $selectedFields
	 */
	protected function createEmptySection(array $selectedFields): mixed
	{
		return [];
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, mixed>
	 */
	abstract protected function loadSelected(array $formIds, array $selectedFields): array;

	/**
	 * @param int[] $formIds
	 * @return int[]
	 */
	private static function normalizeFormIds(array $formIds): array
	{
		$normalized = [];
		foreach ($formIds as $formId)
		{
			$formId = (int)$formId;
			if ($formId > 0)
			{
				$normalized[$formId] = $formId;
			}
		}

		return array_values($normalized);
	}
}
