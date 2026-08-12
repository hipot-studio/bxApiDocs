<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Template\Start;

use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\AutoExecuteFilter;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Query\Query;

final class CollectorService
{
	/**
	 * @var array<string, list<array{id: int, name: string, description: string, parameters: array}>>
	 */
	private static array $cache = [];

	/**
	 * @var array<string, bool>
	 */
	private static array $hasTemplatesCache = [];

	public function hasTemplates(CollectRequest $request): bool
	{
		$cacheKey = $this->buildCacheKey($request);
		if (isset(self::$hasTemplatesCache[$cacheKey]))
		{
			return self::$hasTemplatesCache[$cacheKey];
		}

		self::$hasTemplatesCache[$cacheKey] = ($this->hasLegacyTemplates($request) || $this->hasTriggerTemplates($request));

		return self::$hasTemplatesCache[$cacheKey];
	}

	public function collect(CollectRequest $request): StartableTemplateCollection
	{
		$cacheKey = $this->buildCacheKey($request);
		if (isset(self::$cache[$cacheKey]))
		{
			return $this->buildCollectionFromCache(self::$cache[$cacheKey]);
		}

		$templates = [];

		foreach ($this->collectLegacyTemplates($request) as $template)
		{
			$templates[] = $template;
		}

		foreach ($this->collectTriggerTemplates($request) as $template)
		{
			$templates[] = $template;
		}

		self::$cache[$cacheKey] = $this->normalizeTemplates($templates);

		return $this->buildCollectionFromTemplates($templates);
	}

	private function hasLegacyTemplates(CollectRequest $request): bool
	{
		return $this->hasMatchingRow(
			$this->buildLegacyQuery($request, false),
			function(array $row) use ($request): bool
			{
				return $this->isLegacyTemplateMatch($row, $request);
			},
			true,
		);
	}

	/**
	 * @return StartableTemplateCollection
	 */
	private function collectLegacyTemplates(CollectRequest $request): StartableTemplateCollection
	{
		return $this->collectMatchingTemplates(
			$this->buildLegacyQuery($request, true),
			function(array $row): StartableTemplate
			{
				return new StartableTemplate(
					id: (int)$row['ID'],
					name: $row['NAME'],
					description: $row['DESCRIPTION'] ?? '',
					parameters: $row['PARAMETERS'] ?? [],
				);
			},
			function(array $row) use ($request): bool
			{
				return $this->isLegacyTemplateMatch($row, $request);
			},
		);
	}

	private function hasTriggerTemplates(CollectRequest $request): bool
	{
		$triggerType = $this->getTriggerTypeByEvent($request);
		if ($triggerType === null)
		{
			return false;
		}

		return $this->hasMatchingRow(
			$this->buildTriggerQuery($request, false, $triggerType),
			function(array $row) use ($request): bool
			{
				return $this->isTriggerTemplateMatch($row, $request);
			},
		);
	}

	/**
	 * @return StartableTemplateCollection
	 */
	private function collectTriggerTemplates(CollectRequest $request): StartableTemplateCollection
	{
		$triggerType = $this->getTriggerTypeByEvent($request);
		if ($triggerType === null)
		{
			return new StartableTemplateCollection();
		}

		return $this->collectMatchingTemplates(
			$this->buildTriggerQuery($request, true, $triggerType),
			function(array $row): StartableTemplate
			{
				return new StartableTemplate(
					id: (int)$row['TEMPLATE_ID'],
					name: $row['TEMPLATE_NAME'],
					description: $row['TEMPLATE_DESCRIPTION'] ?? '',
					parameters: $row['TEMPLATE_PARAMETERS'] ?? [],
				);
			},
			function(array $row) use ($request): bool
			{
				return $this->isTriggerTemplateMatch($row, $request);
			},
		);
	}

	private function buildLegacyQuery(CollectRequest $request, bool $forCollection): Query
	{
		[$moduleId, $entity, $documentType] = $request->complexDocumentType;
		$query = WorkflowTemplateTable::query()
			->setSelect($forCollection ? ['ID', 'NAME', 'DESCRIPTION', 'PARAMETERS'] : ['ID', 'PARAMETERS'])
			->where('MODULE_ID', $moduleId)
			->where('ENTITY', $entity)
			->where('DOCUMENT_TYPE', $documentType)
		;

		$this->applyCommonTemplateFilters($query, $request);
		$this->applyAutoExecuteFilter($query, $request->eventType, $request->useAutoExecuteBitmask);
		$this->applyParameterizedFilter($query, 'PARAMETERS', $request->onlyParameterized);

		if ($forCollection)
		{
			$query->setOrder(['SORT' => 'ASC', 'NAME' => 'ASC']);
		}

		return $query;
	}

	private function buildTriggerQuery(CollectRequest $request, bool $forCollection, string $triggerType): Query
	{
		[$moduleId, $entity, $documentType] = $request->complexDocumentType;
		$query = WorkflowTemplateTriggerTable::query()
			->setSelect(
				$forCollection
					? [
						'TEMPLATE_ID',
						'APPLY_RULES',
						'TEMPLATE_NAME' => 'TEMPLATE.NAME',
						'TEMPLATE_DESCRIPTION' => 'TEMPLATE.DESCRIPTION',
						'TEMPLATE_PARAMETERS' => 'TEMPLATE.PARAMETERS',
					]
					: [
						'TEMPLATE_ID',
						'APPLY_RULES',
						'TEMPLATE_PARAMETERS' => 'TEMPLATE.PARAMETERS',
					],
			)
			->where('TRIGGER_TYPE', $triggerType)
			->where('MODULE_ID', $moduleId)
			->where('ENTITY', $entity)
			->where('DOCUMENT_TYPE', $documentType)
		;

		$this->applyCommonTemplateFilters($query, $request, 'TEMPLATE.');
		$this->applyParameterizedFilter($query, 'TEMPLATE.PARAMETERS', $request->onlyParameterized);

		if ($forCollection)
		{
			$query->setOrder(['TEMPLATE.SORT' => 'ASC', 'TEMPLATE.NAME' => 'ASC', 'TRIGGER_NAME' => 'ASC']);
		}

		return $query;
	}

	private function applyCommonTemplateFilters(Query $query, CollectRequest $request, string $fieldPrefix = ''): void
	{
		if ($request->requireActive)
		{
			$query->where($fieldPrefix . 'ACTIVE', 'Y');
		}

		if ($request->excludeSystem)
		{
			$query->where($fieldPrefix . 'IS_SYSTEM', 'N');
		}
	}

	private function applyParameterizedFilter(Query $query, string $fieldName, bool $onlyParameterized): void
	{
		if ($onlyParameterized)
		{
			$query->whereNotNull($fieldName);
		}
	}

	private function hasMatchingRow(Query $query, callable $isMatchingRow, bool $exact = false): bool
	{
		if ($exact)
		{
			$query->setLimit(1);

			return (bool)$query->exec()->fetch();
		}

		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			if ($isMatchingRow($row))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param callable(array):StartableTemplate $rowMapper
	 * @param callable(array):bool $isMatchingRow
	 */
	private function collectMatchingTemplates(Query $query, callable $rowMapper, callable $isMatchingRow): StartableTemplateCollection
	{
		$collection = new StartableTemplateCollection();
		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			if (!$isMatchingRow($row))
			{
				continue;
			}

			$collection->add($rowMapper($row));
		}

		return $collection;
	}

	private function isLegacyTemplateMatch(array $row, CollectRequest $request): bool
	{
		return !($request->onlyParameterized && !($row['PARAMETERS'] ?? []));
	}

	private function isTriggerTemplateMatch(array $row, CollectRequest $request): bool
	{
		if (!$this->isMatchingCategory($row['APPLY_RULES'] ?? [], $request->categoryId))
		{
			return false;
		}

		if ($request->onlyParameterized && !($row['TEMPLATE_PARAMETERS'] ?? []))
		{
			return false;
		}

		return true;
	}

	private function getTriggerTypeByEvent(CollectRequest $request): ?string
	{
		$moduleSettings = \CBPRuntime::getRuntime()->getDocumentService()->getStarterModuleSettings(
			$request->complexDocumentType,
		);

		if ($moduleSettings === null)
		{
			return null;
		}

		if (
			$request->eventType === \CBPDocumentEventType::Create
			|| $request->eventType === \CBPDocumentEventType::Edit
		)
		{
			$descriptor =
				$request->eventType === \CBPDocumentEventType::Create
					? $moduleSettings->getCreateDocumentTrigger()
					: $moduleSettings->getEditDocumentTrigger()
			;

			return $descriptor instanceof TriggerDescriptorDto && $descriptor->triggerType !== ''
				? $descriptor->triggerType
				: null;
		}

		return null;
	}

	private function isMatchingCategory(array $applyRules, ?int $categoryId): bool
	{
		$ruleCategoryId = $applyRules['Properties']['categoryId'] ?? null;
		if ($ruleCategoryId === null || $ruleCategoryId === '')
		{
			return true;
		}

		if ($categoryId === null)
		{
			return false;
		}

		return (int)$ruleCategoryId === $categoryId;
	}

	private function applyAutoExecuteFilter(Query $query, int $eventType, bool $useAutoExecuteBitmask): void
	{
		if (!$useAutoExecuteBitmask)
		{
			$query->where('AUTO_EXECUTE', $eventType);

			return;
		}

		$filterValue = AutoExecuteFilter::getFilterValue($eventType);
		if (is_array($filterValue))
		{
			$query->whereIn('AUTO_EXECUTE', $filterValue);

			return;
		}

		$query->where('AUTO_EXECUTE', $filterValue);
	}

	private function buildCacheKey(CollectRequest $request): string
	{
		return md5(serialize([
			$request->complexDocumentType,
			$request->eventType,
			$request->categoryId,
			$request->onlyParameterized,
			$request->requireActive,
			$request->excludeSystem,
			$request->useAutoExecuteBitmask,
		]));
	}

	/**
	 * @param list<StartableTemplate> $templates
	 * @return list<array{id: int, name: string, description: string, parameters: array}>
	 */
	private function normalizeTemplates(array $templates): array
	{
		$normalizedTemplates = [];

		foreach ($templates as $template)
		{
			$normalizedTemplates[] = [
				'id' => $template->id,
				'name' => $template->name,
				'description' => $template->description,
				'parameters' => $template->parameters,
			];
		}

		return $normalizedTemplates;
	}

	/**
	 * @param list<array{id: int, name: string, description: string, parameters: array}> $templates
	 */
	private function buildCollectionFromCache(array $templates): StartableTemplateCollection
	{
		$collection = new StartableTemplateCollection();

		foreach ($templates as $template)
		{
			$collection->add(
				new StartableTemplate(
					id: $template['id'],
					name: $template['name'],
					description: $template['description'],
					parameters: $template['parameters'],
				),
			);
		}

		return $collection;
	}

	/**
	 * @param list<StartableTemplate> $templates
	 */
	private function buildCollectionFromTemplates(array $templates): StartableTemplateCollection
	{
		$collection = new StartableTemplateCollection();

		foreach ($templates as $template)
		{
			$collection->add($template);
		}

		return $collection;
	}
}
