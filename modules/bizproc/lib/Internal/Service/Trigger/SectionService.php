<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger;

use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

final class SectionService
{
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
		static $cachedTemplateSections = [];

		if (!empty($cachedTemplateSections[$templateId]))
		{
			return $cachedTemplateSections[$templateId];
		}

		$templateRow = WorkflowTemplateTable::getById($templateId)->fetch();

		$template = $templateRow['TEMPLATE'][0] ?? [];

		if (!$template)
		{
			return [];
		}

		$triggers = WorkflowTemplateTriggerTable::filterTriggersByActivities($template['Children']);

		$cachedTemplateSections = [];

		foreach ($triggers as $trigger)
		{
			/** @var Configurator $configuration */
			$configuration = $trigger['CONFIGURATION'];
			$section = $configuration->getSection();

			if ($section)
			{
				$cachedTemplateSections[$trigger['TRIGGER_TYPE']] = $configuration->getSection();
			}
		}

		return $cachedTemplateSections;
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
		$sections = $this->getSectionsByTemplateId($templateId);

		foreach ($sections as $triggerType => $section)
		{
			if ($section->id === $sectionString && ($path === null || $path === $section->path))
			{
				return $triggerType;
			}
		}

		return null;
	}
}

