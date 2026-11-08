<?php

namespace Bitrix\Sign\Service\Integration\Bizproc;

use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Item\Process\AvailableProcess;
use Bitrix\Sign\Item\Process\AvailableProcessCollection;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\ProcessTypeService;

/**
 * Processes of the KEDO section available to an employee for a manual start. The set is an intersection of
 * two mechanisms of bizproc, and neither half is enough alone: the section of the start node tells our
 * templates from the rest of the portal but knows nothing about permissions, while the start list of a
 * document type checks the permissions but knows nothing about the section.
 *
 * This is the only place the set is resolved: the submenu, the grid of all processes and the visibility of
 * the menu item ask the same service, so a process available in one of them is available in all three.
 */
final class ProcessCatalogService
{
	private const SECTION_ID = 'sign|b2e';

	private ProcessTypeService $processTypeService;
	/** @var null|\Closure(int, array, array): array */
	private ?\Closure $startableTemplatesReader;

	/** @var array<int, bool> */
	private array $hasAnyByUser = [];

	public function __construct(
		?ProcessTypeService $processTypeService = null,
		?\Closure $startableTemplatesReader = null,
	)
	{
		$this->processTypeService = $processTypeService ?? Container::instance()->getCrmProcessTypeService();
		$this->startableTemplatesReader = $startableTemplatesReader;
	}

	public function getAvailableProcesses(
		int $userId,
		?string $searchQuery = null,
		?int $limit = null,
	): AvailableProcessCollection
	{
		$rows = $this->findStartableRows($userId, $searchQuery, $limit);
		if (trim($searchQuery ?? '') === '' && ($limit === null || $limit > 0))
		{
			$this->hasAnyByUser[$userId] = $rows !== [];
		}

		return $rows === [] ? AvailableProcessCollection::emptyList() : $this->buildProcesses($rows);
	}

	/**
	 * Returns one process only after the shared bizproc start permission check.
	 */
	public function getByIdForStart(int $userId, int $processId): ?AvailableProcess
	{
		$rows = $this->findStartableRows($userId, null, 1, $processId);
		if ($rows === [])
		{
			return null;
		}

		return $this->buildProcesses($rows)->findById($processId);
	}

	/**
	 * Asked on every load of the page of personnel documents, so the answer is kept for the request and the
	 * search stops on the first process found instead of building the whole list.
	 */
	public function hasAny(int $userId): bool
	{
		if (!isset($this->hasAnyByUser[$userId]))
		{
			$this->hasAnyByUser[$userId] = $this->findStartableRows($userId, null, 1) !== [];
		}

		return $this->hasAnyByUser[$userId];
	}

	/**
	 * The intersection itself, kept in one place so that the list and the "is there any" flag can never
	 * answer differently. The order is the one of the start list of bizproc (SORT, then NAME).
	 *
	 * The templates of the section are asked for first: a portal that has no processes of the section pays
	 * no per-row permission check at all, and that is the state hasAny() meets most often.
	 *
	 * @return array<int, array> rows of the start list of bizproc, keyed by the identifier of the template
	 */
	private function findStartableRows(
		int $userId,
		?string $searchQuery,
		?int $limit,
		?int $processId = null,
	): array
	{
		if (
			$userId < 1
			|| ($limit !== null && $limit < 1)
			|| ($processId !== null && $processId < 1)
			|| !Loader::includeModule('bizproc')
		)
		{
			return [];
		}

		$documentType = $this->getDocumentType();
		if ($documentType === null)
		{
			return [];
		}

		if ($processId === null)
		{
			$sectionTemplateIds = $this->getSectionTemplateIds();
			if ($sectionTemplateIds === [])
			{
				return [];
			}
		}
		else
		{
			if (!$this->isTemplateInSection($processId))
			{
				return [];
			}

			$sectionTemplateIds = [$processId => true];
		}

		$rows = [];
		$selection = [
			'searchQuery' => $searchQuery,
			'limit' => $limit,
		];
		if ($processId === null)
		{
			$selection['templateIds'] = array_keys($sectionTemplateIds);
		}
		else
		{
			$selection['templateId'] = $processId;
		}

		foreach ($this->readStartableTemplates($userId, $documentType, $selection) as $row)
		{
			$id = (int)($row['id'] ?? 0);
			if (!isset($sectionTemplateIds[$id]))
			{
				continue;
			}

			$rows[$id] = $row;
			if ($limit !== null && count($rows) >= $limit)
			{
				break;
			}
		}

		return $rows;
	}

	private function readStartableTemplates(int $userId, array $documentType, array $selection): array
	{
		if ($this->startableTemplatesReader !== null)
		{
			return ($this->startableTemplatesReader)($userId, $documentType, $selection);
		}

		return \CBPDocument::getTemplatesForStartLimited(
			$userId,
			$documentType,
			null,
			[],
			$selection,
		);
	}

	/**
	 * Templates of the section as a map for the lookup by identifier. The service lies in the Internal
	 * namespace of bizproc, so the call is isolated in this single place - the way the grid of bizproc itself
	 * keeps it.
	 *
	 * @return array<int, true>
	 */
	private function getSectionTemplateIds(): array
	{
		$templateIds = \Bitrix\Bizproc\Internal\Container::getSectionService()
			->getMatchedTemplateIdsBySection(new Section(self::SECTION_ID))
		;

		return array_fill_keys(array_map('intval', $templateIds), true);
	}

	private function isTemplateInSection(int $templateId): bool
	{
		$sectionRows = \Bitrix\Bizproc\Internal\Container::getSectionService()
			->getTemplateSectionsMap([$templateId])[$templateId] ?? []
		;
		foreach ($sectionRows as $sectionRow)
		{
			$path = $sectionRow['PATH'] ?? null;
			if ($sectionRow['SECTION_ID'] === self::SECTION_ID && ($path === null || $path === ''))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Document type of the processes - the smart process the personnel processes of the portal run on. An
	 * absent type is an ordinary state of a portal with the feature closed, not a failure.
	 */
	private function getDocumentType(): ?array
	{
		$entityTypeId = $this->processTypeService->getEntityTypeId();
		if ($entityTypeId === null || !Loader::includeModule('crm'))
		{
			return null;
		}

		return \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
	}

	/**
	 * @param array<int, array> $rows
	 */
	private function buildProcesses(array $rows): AvailableProcessCollection
	{
		$modificationByTemplate = $this->loadModification(array_keys($rows));

		$processes = AvailableProcessCollection::emptyList();
		foreach ($rows as $id => $row)
		{
			$processes->add(
				new AvailableProcess(
					id: $id,
					name: (string)($row['name'] ?? ''),
					description: (string)($row['description'] ?? ''),
					hasParameters: (bool)($row['hasParameters'] ?? false),
					isConstantsTuned: (bool)($row['isConstantsTuned'] ?? false),
					modifiedTs: $modificationByTemplate[$id]['modifiedTs'] ?? 0,
					authorId: $modificationByTemplate[$id]['authorId'] ?? 0,
				),
			);
		}

		return $processes;
	}

	/**
	 * The moment of the last change and the author are not part of the start list of bizproc, so they are
	 * loaded for the whole page by one query instead of one query per row. The entity is read instead of
	 * CBPWorkflowTemplateLoader::getList(): the loader formats MODIFIED into a string of the current culture
	 * ({@see \CBPWorkflowTemplateResult::fetch()}), and the field of the client is a timestamp.
	 *
	 * @param int[] $templateIds
	 *
	 * @return array<int, array{modifiedTs: int, authorId: int}>
	 */
	private function loadModification(array $templateIds): array
	{
		$rows = WorkflowTemplateTable::query()
			->setSelect(['ID', 'MODIFIED', 'USER_ID'])
			->whereIn('ID', $templateIds)
			->fetchAll()
		;

		$modificationByTemplate = [];
		foreach ($rows as $row)
		{
			$modified = $row['MODIFIED'] ?? null;
			$modificationByTemplate[(int)$row['ID']] = [
				'modifiedTs' => $modified instanceof DateTime ? $modified->getTimestamp() : 0,
				'authorId' => (int)($row['USER_ID'] ?? 0),
			];
		}

		return $modificationByTemplate;
	}
}
