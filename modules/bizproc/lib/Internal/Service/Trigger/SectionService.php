<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger;

use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Collection;

final class SectionService
{
	/** @var array<int, array<int, array{TEMPLATE_ID:int, SECTION_ID:string, PATH:?string}>> */
	private array $templateSectionsMap = [];

	/** @var array<int, array<int, array{TRIGGER_TYPE:?string, SECTION_ID:string, SECTION_PATH:?string}>> */
	private array $templateTriggerSectionsMap = [];

	/** @var array<string, list<int>> */
	private array $matchedTemplateIdsBySection = [];

	/**
	 * @param int[] $templateIds
	 *
	 * @return array<int, array<int, array{TEMPLATE_ID:int, SECTION_ID:string, PATH:?string}>>
	 */
	public function getTemplateSectionsMap(array $templateIds): array
	{
		Collection::normalizeArrayValuesByInt($templateIds);
		if ($templateIds === [])
		{
			return [];
		}

		$requestedTemplateIds = array_flip($templateIds);
		$missingTemplateIds = array_values(array_diff($templateIds, array_keys($this->templateSectionsMap)));
		if (!$missingTemplateIds)
		{
			return array_intersect_key($this->templateSectionsMap, $requestedTemplateIds);
		}

		foreach ($missingTemplateIds as $templateId)
		{
			$this->templateSectionsMap[$templateId] = [];
		}

		$sectionsResult =
			WorkflowTemplateSectionTable::query()
				->setSelect(['TEMPLATE_ID', 'SECTION_ID', 'PATH'])
				->whereIn('TEMPLATE_ID', $missingTemplateIds)
				->exec()
		;

		while ($section = $sectionsResult->fetch())
		{
			$templateId = (int)($section['TEMPLATE_ID'] ?? 0);
			$this->templateSectionsMap[$templateId][] = [
				'TEMPLATE_ID' => $templateId,
				'SECTION_ID' => (string)($section['SECTION_ID'] ?? ''),
				'PATH' => isset($section['PATH']) ? (string)$section['PATH'] : null,
			];
		}

		return array_intersect_key($this->templateSectionsMap, $requestedTemplateIds);
	}

	/**
	 * @param int[] $templateIds
	 *
	 * @return array<int, array<int, array{TRIGGER_TYPE:?string, SECTION_ID:string, SECTION_PATH:?string}>>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function getTemplateTriggerSectionsMap(array $templateIds): array
	{
		Collection::normalizeArrayValuesByInt($templateIds);
		if ($templateIds === [])
		{
			return [];
		}

		$requestedTemplateIds = array_flip($templateIds);
		$missingTemplateIds = array_values(array_diff($templateIds, array_keys($this->templateTriggerSectionsMap)));
		if (!$missingTemplateIds)
		{
			return array_intersect_key($this->templateTriggerSectionsMap, $requestedTemplateIds);
		}

		foreach ($missingTemplateIds as $templateId)
		{
			$this->templateTriggerSectionsMap[$templateId] = [];
		}

		$templateRowsResult =
			WorkflowTemplateTable::query()
				->setSelect(['ID', 'TEMPLATE'])
				->whereIn('ID', $missingTemplateIds)
				->exec()
		;
		while ($templateRow = $templateRowsResult->fetch())
		{
			$templateId = (int)($templateRow['ID'] ?? 0);
			$template = $templateRow['TEMPLATE'][0] ?? null;
			$templateChildren = is_array($template) ? ($template['Children'] ?? []) : [];
			$triggers = WorkflowTemplateTriggerTable::filterTriggersByActivities($templateChildren);
			$triggerSections = [];
			foreach ($triggers as $trigger)
			{
				$section = ($trigger['CONFIGURATION'] ?? null)?->getSection();
				if ($section === null)
				{
					continue;
				}

				$triggerSection = [
					'TRIGGER_TYPE' => $trigger['TRIGGER_TYPE'] ?? null,
					'SECTION_ID' => $section->id,
					'SECTION_PATH' => $section->path,
				];
				$triggerSections[$this->buildTriggerSectionCacheKey($triggerSection)] = $triggerSection;
			}

			$this->templateTriggerSectionsMap[$templateId] = array_values($triggerSections);
		}

		return array_intersect_key($this->templateTriggerSectionsMap, $requestedTemplateIds);
	}

	/**
	 * @param int $templateId
	 *
	 * @return Section[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function getSectionsByTemplateId(int $templateId): array
	{
		$result = [];
		$triggerSections = $this->getTemplateTriggerSectionsMap([$templateId])[$templateId] ?? [];
		foreach ($triggerSections as $triggerSection)
		{
			$triggerType = $triggerSection['TRIGGER_TYPE'] ?? null;
			if (!is_string($triggerType) || $triggerType === '')
			{
				continue;
			}

			$result[$triggerType] = new Section(
				$triggerSection['SECTION_ID'],
				$triggerSection['SECTION_PATH'] ?? null,
			);
		}

		return $result;
	}

	public function getMatchedTemplateIdsBySection(Section $section): array
	{
		$cacheKey = $this->buildSectionCacheKey($section);
		if (array_key_exists($cacheKey, $this->matchedTemplateIdsBySection))
		{
			return $this->matchedTemplateIdsBySection[$cacheKey];
		}

		$rows = WorkflowTemplateSectionTable::query()
			->setSelect(['TEMPLATE_ID', 'PATH'])
			->where('SECTION_ID', $section->id)
			->exec()
		;

		$templateIds = [];
		$hasSectionRows = false;
		while ($row = $rows->fetch())
		{
			$hasSectionRows = true;

			if (!$this->isMatchingSectionPath(isset($row['PATH']) ? (string)$row['PATH'] : null, $section->path))
			{
				continue;
			}

			$templateId = (int)($row['TEMPLATE_ID'] ?? 0);
			if ($templateId > 0)
			{
				$templateIds[$templateId] = $templateId;
			}
		}

		$this->matchedTemplateIdsBySection[$cacheKey] = $hasSectionRows ? array_values($templateIds) : [];

		return $this->matchedTemplateIdsBySection[$cacheKey];
	}

	/**
	 * @param int $templateId
	 * @param string $sectionString
	 * @param string|null $path
	 *
	 * @return string
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function getTriggerTypeByTemplateAndSectionString(int $templateId, string $sectionString, ?string $path = null): ?string
	{
		$triggerSections = $this->getTemplateTriggerSectionsMap([$templateId])[$templateId] ?? [];
		foreach ($triggerSections as $triggerSection)
		{
			if (
				$triggerSection['SECTION_ID'] === $sectionString
				&& ($path === null || $path === ($triggerSection['SECTION_PATH']))
			)
			{
				return $triggerSection['TRIGGER_TYPE'];
			}
		}

		return null;
	}

	private function buildSectionCacheKey(Section $section): string
	{
		return serialize([$section->id, $section->path]);
	}

	private function buildTriggerSectionCacheKey(array $triggerSection): string
	{
		return serialize([
			$triggerSection['TRIGGER_TYPE'],
			$triggerSection['SECTION_ID'],
			$triggerSection['SECTION_PATH'],
		]);
	}

	private function isMatchingSectionPath(?string $rowPath, ?string $sectionPath): bool
	{
		if ($rowPath === null || $rowPath === '')
		{
			return true;
		}

		return $sectionPath !== null && $rowPath === $sectionPath;
	}
}
