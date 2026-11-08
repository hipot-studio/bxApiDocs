<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents;

use Bitrix\Bizproc\Internal\Integration\Rag\DocumentFieldTypes\RagKnowledgeBaseType;
use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDtoCollection;
use Bitrix\Bizproc\Internal\Integration\Rag\FileStatus;
use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseGetInfoResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\KnowledgeBaseFileCacheService;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\KnowledgeBaseFileService;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\KnowledgeBaseService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\FileTable;
use Bitrix\Main\Engine\CurrentUser;
use CBPHelper;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Engine\Response\Converter;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\AiAgentsFilter;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\AiAgentsFilterSettings;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Service\TemplateBotUsageService;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Service\TemplateProjectUsageService;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Visibility\HiddenAiAgentsRegistry;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\Query\LaunchedCopyQuery;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\UpgradeFeatureToggle;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\UpgradeAvailabilityService;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use CBPWorkflowTemplateUser;


/**
 * Helper class that encapsulates grid creation and data fetching logic.
 */
class AiAgentsGridHelper
{
	private const GRID_ID = 'BIZPROC_AI_AGENTS_GRID';
	private const DEFAULT_PAGE_SIZE = 20;
	private string $navParamName;
	private ?AiAgentsGrid $grid = null;
	private HiddenAiAgentsRegistry $hiddenAiAgents;
	private UpgradeAvailabilityService $upgradeAvailabilityService;
	private TemplateBotUsageService $templateBotUsageService;
	private SetupTemplateService $setupTemplateService;
	private AiAgentProvider $aiAgentProvider;
	private TemplateAccessService $templateAccessService;
	private TemplateProjectUsageService $templateProjectUsageService;

	public function __construct(
		?HiddenAiAgentsRegistry $hiddenAiAgents = null,
		?UpgradeAvailabilityService $upgradeAvailabilityService = null,
		?TemplateBotUsageService $templateBotUsageService = null,
		?SetupTemplateService $setupTemplateService = null,
		?AiAgentProvider $aiAgentProvider = null,
		?TemplateAccessService $templateAccessService = null,
		?TemplateProjectUsageService $templateProjectUsageService = null,
	)
	{
		$this->navParamName = self::GRID_ID . '_nav';
		$this->hiddenAiAgents = $hiddenAiAgents ?? ServiceLocator::getInstance()->get(HiddenAiAgentsRegistry::class);
		$this->upgradeAvailabilityService = $upgradeAvailabilityService ?? new UpgradeAvailabilityService();
		$this->templateBotUsageService
			= $templateBotUsageService ?? ServiceLocator::getInstance()->get(TemplateBotUsageService::class);
		$this->setupTemplateService = $setupTemplateService ?? new SetupTemplateService();
		// The very instance the row actions ask for the transfer right, so the page warmup below reaches them.
		$this->aiAgentProvider = $aiAgentProvider ?? ServiceLocator::getInstance()->get(AiAgentProvider::class);
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
		$this->templateProjectUsageService = $templateProjectUsageService ?? new TemplateProjectUsageService();

		Loader::requireModule('humanresources');
	}

	public function getGridId(): string
	{
		return self::GRID_ID;
	}

	public function getNavParamName(): string
	{
		return $this->navParamName;
	}

	/**
	 * @param array $arParams Component parameters (used for export mode/type).
	 */
	public function createGrid(array $arParams): AiAgentsGrid
	{
		if (isset($this->grid))
		{
			return $this->grid;
		}

		$settings = new AiAgentsSettings([
			'ID' => self::GRID_ID,
			'SHOW_ROW_CHECKBOXES' => false,
			'MODE' => $arParams['EXPORT_TYPE'] ?? 'html',
		]);

		$this->grid = new AiAgentsGrid($settings);

		// The lazy total counter runs in its own ajax request, so it must repeat the filter the
		// page fetch applied; otherwise the widget reports every agent of the section.
		$this->grid->setTotalCountCalculator(function ()
		{
			return $this->getTotalCount($this->getCurrentFilterData());
		});

		return $this->grid;
	}

	/**
	 * Build parameters array for ComponentParams::get used to render grid navigation and settings.
	 */
	public function buildGridParams(AiAgentsGrid $grid, int $currentPage): array
	{
		return [
			'SHOW_ROW_ACTIONS_MENU' => true,
			'SHOW_ROW_CHECKBOXES' => true,
			'SHOW_SELECTED_COUNTER' => true,
			'SHOW_ACTION_PANEL' => true,
			'NAV_COMPONENT_TEMPLATE' => 'modern',
			'TOTAL_ROWS_COUNT_HTML' => $grid->getTotalRowsCountHtml(),
			'SHOW_PAGINATION' => true,
			'SHOW_TOTAL_COUNTER' => true,
			'SHOW_PAGESIZE' => true,
			'SHOW_GRID_SETTINGS_MENU' => true,
			'SHOW_NAVIGATION_PANEL' => true,
			'SHOW_MORE_BUTTON' => true,
			'ENABLE_NEXT_PAGE' => $grid->hasNextPage(),
			'CURRENT_PAGE' => $currentPage,
			'NAV_PARAM_NAME' => $this->navParamName,
		];
	}

	/**
	 * @param array{limit?: int, offset?: int} $ormParams
	 */
	public function getGridDataWithOrmParams(array $ormParams): array
	{
		$limit = $ormParams['limit'] ?? self::DEFAULT_PAGE_SIZE;
		$offset = $ormParams['offset'] ?? 0;

		return $this->getGridData($limit, $offset, $this->getCurrentFilterData());
	}

	private function getCurrentFilterData(): array
	{
		return (new \Bitrix\Main\UI\Filter\Options($this->getGridId()))->getFilter();
	}

	public function getBaseBizprocDesignerUri(): Uri
	{
		return new Uri("/bizprocdesigner/editor/");
	}

	public function prepareGridRowDataFromTemplateFields(array $templateFields): array
	{
		$result = [
			'id' => -1,
			'columns' => [],
			'actions' => [],
		];

		$templateId = $templateFields['ID'] ?? -1;
		$templateData = $this->enrichTemplatesWithRelatedData(
			[
				$templateId => $templateFields,
			],
		);

		$grid = $this->createGrid([]);
		$grid->setRawRows($templateData);
		$gridRows = $grid->prepareRows();
		if (empty($gridRows))
		{
			return $result;
		}

		$gridRowData = $gridRows[0];

		if (
			empty($gridRowData)
			|| !isset($gridRowData['columns'])
		)
		{
			return $result;
		}

		$columns = $gridRowData['columns'];
		$columns['ID'] = (string)$templateId;

		if (is_array($columns))
		{
			$converter = new Converter(Converter::OUTPUT_JSON_FORMAT);

			$result['id'] = $templateId;
			$result['columns'] = $columns;
			$result['actions'] = $converter->process($gridRowData['actions'] ?? []);
		}

		return $result;
	}

	public function getRowFieldsByTemplateId(int $templateId): array
	{
		$query = $this->getAiAgentsTemplatesQuery();
		$query->addSelect('TEMPLATE');
		$query->where('ID', $templateId);
		$templateFields = $query->fetchAll();

		if (
			empty($templateFields)
			|| !is_array($templateFields[0] ?? null)
		)
		{
			return [];
		}

		return $this->prepareGridRowDataFromTemplateFields($templateFields[0]);
	}

	private function getGridData(int $limit, int $offset, array $filterData): array
	{
		$templates = $this->getAiAgentsTemplates($limit, $offset, $filterData);

		if (!$templates)
		{
			return [];
		}

		return $templates;
	}

	private function getTotalCount(array $filterData): int
	{
		$query = $this->getAiAgentsTemplatesQuery();
		$this->applyFilterToQuery($query, $filterData);

		// Counted by wrapping the query into a subselect: the AGENT_TEMPLATE filter adds a
		// GROUP BY, and an aggregated query can neither be fetched as objects nor counted with
		// a plain COUNT(*) over the joined rows.
		return (int)$query->queryCountTotal();
	}

	private function getAiAgentsTemplatesQuery(?int $limit = null, ?int $offset = null): Query
	{
		$query = WorkflowTemplateTable::query()
			->setSelect([
				'ID',
				'MODULE_ID',
				'ENTITY',
				'NAME',
				'DESCRIPTION',
				'DOCUMENT_TYPE',
				'CONSTANTS',
				'SYSTEM_CODE',
				'ACTIVATED_BY',
				'ACTIVATED_AT',
				'CREATE_SOURCE',
				'IS_MODIFIED',
				'MODIFIED',
			])
			// Section membership is a semi-join, not a relation: a template may own several
			// section rows, and joining them would multiply the template row in the grid.
			->whereIn(
				'ID',
				WorkflowTemplateSectionTable::query()
					->setSelect(['TEMPLATE_ID'])
					->where('SECTION_ID', WorkflowTemplateSection::AiAgent->value),
			)
			->setOrder([
				'ACTIVE' => 'DESC',
				'ACTIVATED_AT' => 'DESC',
				'ID' => 'DESC',
			])
		;

		$hiddenSystemCodes = $this->hiddenAiAgents->getHiddenSystemCodes();
		if ($hiddenSystemCodes)
		{
			$query->where(
				Query::filter()
					->logic('or')
					->where('SYSTEM_CODE', null)
					->whereNotIn('SYSTEM_CODE', $hiddenSystemCodes),
			);
		}

		if (!is_null($limit))
		{
			$query->setLimit($limit);
		}

		if (!is_null($offset))
		{
			$query->setOffset($offset);
		}

		return $query;
	}

	private function getAiAgentsTemplates(int $limit, int $offset, array $filterData): array
	{
		$query = $this->getAiAgentsTemplatesQuery($limit, $offset);
		// TEMPLATE body is needed to detect "chatbot setup" activities; it is added only to the
		// page fetch (not to the shared query reused by the unbounded total-count calculator).
		$query->addSelect('TEMPLATE');
		$this->applyFilterToQuery($query, $filterData);
		$templates = $query->fetchAll();

		if (empty($templates))
		{
			return [];
		}

		return $this->enrichTemplatesWithRelatedData($templates);
	}

	/**
	 * Orchestrates the process of enriching templates with related user and department data.
	 *
	 * @param array $templates Raw templates from the database.
	 * @return array Enriched templates.
	 */
	private function enrichTemplatesWithRelatedData(array $templates): array
	{
		$allUserIds = [];
		$allDepartmentIds = [];
		$allRecursiveDepartmentIds = [];
		$idMapByTemplate = [];
		$ragFileIds = [];
		$templateIds = [];
		$projectIdsByTemplate = [];

		foreach ($templates as $template)
		{
			$templateId = (int)$template['ID'];
			$templateIds[] = $templateId;
			$launchedById = $this->getUserIdFromString($template['ACTIVATED_BY']);
			if ($launchedById)
			{
				$allUserIds[] = $launchedById;
			}

			$extractedIds = $this->extractIdsFromConstants($template);
			if ($extractedIds['projectIds'] !== [])
			{
				$projectIdsByTemplate[$templateId] = $extractedIds['projectIds'];
			}

			$allUserIds = [...$allUserIds, ...$extractedIds['userIds']];
			$allDepartmentIds = [...$allDepartmentIds, ...$extractedIds['departmentIds']];
			$allRecursiveDepartmentIds = [...$allRecursiveDepartmentIds, ...$extractedIds['recursiveDepartmentIdsFromConstant']];
			$ragFilesStatuses = $this->getRagFilesStatuses($template);
			$ragFileIds = [...$ragFileIds, ...(array)$ragFilesStatuses?->getFileIds()];

			$idMapByTemplate[$templateId] = [
				'launchedById' => $launchedById,
				'usedByUserIds' => $extractedIds['userIds'],
				'departmentIds' => $extractedIds['departmentIds'],
				'recursiveDepartmentIds' => $extractedIds['recursiveDepartmentIdsFromConstant'],
				'ragFilesStatuses' => $ragFilesStatuses,
			];
		}

		$projectUserIdsByTemplate = $this->templateProjectUsageService->getUserIdsByTemplate(
			$projectIdsByTemplate,
			(int)CurrentUser::get()->getId(),
			true,
		);
		foreach ($projectUserIdsByTemplate as $templateId => $userIds)
		{
			$idMapByTemplate[$templateId]['usedByUserIds'] = array_values(array_unique([
				...$idMapByTemplate[$templateId]['usedByUserIds'],
				...$userIds,
			]));
			$allUserIds = [...$allUserIds, ...$userIds];
		}

		$templateBotIdMap = $this->templateBotUsageService->getBotIdsByTemplate($templateIds, $templates);

		$allBotIds = array_merge(...array_values($templateBotIdMap));
		$allUserIds = [...$allUserIds, ...$allBotIds];

		$childDepartmentMap = [];
		if (!empty($allRecursiveDepartmentIds))
		{
			$uniqueRecursiveIds = array_unique($allRecursiveDepartmentIds);
			$childDepartmentMap = $this->fetchChildDepartmentMap($uniqueRecursiveIds);

			foreach ($childDepartmentMap as $child)
			{
				$allDepartmentIds = [...$allDepartmentIds, ...$child];
			}
		}

		$allDepartmentIds = [...$allDepartmentIds, ...$allRecursiveDepartmentIds];
		$users = $this->fetchUsersByIds(array_values(array_unique($allUserIds)));
		$departments = $this->fetchDepartmentsByIds(array_values(array_unique($allDepartmentIds)));

		$ragFileNames = $this->fetchRagFileNameByFileIds($ragFileIds);
		$this->fileRagFileNamesToIdMapByTemplate($idMapByTemplate, $ragFileNames);

		$templateChatsMap = $this->prepareTemplateChatsMap($templateBotIdMap, $users);

		$enriched = $this->attachRelatedDataToTemplates(
			$templates,
			$idMapByTemplate,
			$users,
			$departments,
			$childDepartmentMap,
			$templateChatsMap,
		);

		$this->warmupTemplateEditRights($templateIds);
		$this->warmupTransferRights($templateIds);

		return $this->attachVersionAvailabilityToTemplates($enriched);
	}

	/**
	 * @param list<int> $templateIds
	 */
	private function warmupTemplateEditRights(array $templateIds): void
	{
		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		$this->templateAccessService->warmupAuthorshipForPermission(
			$templateIds,
			PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
			(int)$currentUser->getId(),
		);
	}

	/**
	 * Batch-warms the transfer-ownership predicate for the whole page up front, so the per-row
	 * ChangeOwnerAction reads it from the provider instead of asking the database one to three times per
	 * launched copy (grid hot-path N+1, NFR P95). Same reason and same place as preloadOriginSettings()
	 * below; the acting user comes from server-side state, exactly as the row action resolves it.
	 *
	 * @param list<int> $templateIds
	 */
	private function warmupTransferRights(array $templateIds): void
	{
		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		$this->aiAgentProvider->warmupTransferRights($templateIds, (int)$currentUser->getId());
	}

	/**
	 * Enriches launched copies with the DTO-01 fields the version cell and the upgrade action read
	 * off the row: hasNewVersion, isCustomized, isComparable, hasSystemOrigin, currentVersionLabel,
	 * newVersionLabel.
	 *
	 * Both readers of those fields are closed by the same flag, so a disabled feature skips the
	 * whole enrichment here rather than at the call sites: the decision stays in one place, and a
	 * future caller cannot walk around it. Every reader takes the fields as optional.
	 *
	 * @param array $templates
	 * @return array
	 */
	private function attachVersionAvailabilityToTemplates(array $templates): array
	{
		if (!UpgradeFeatureToggle::isEnabled())
		{
			return $templates;
		}

		// Batch-load ORIGIN_SYSTEM_CODE/VERSION for the whole page in a single query up front, so
		// the per-row getAvailabilityForRow() below reads them from the service cache instead of
		// issuing two settings lookups per launched copy (grid hot-path N+1, NFR P95).
		$this->upgradeAvailabilityService->preloadOriginSettings($templates);

		foreach ($templates as &$template)
		{
			$template += $this->upgradeAvailabilityService->getAvailabilityForRow($template)->toArray();
		}
		unset($template);

		return $templates;
	}

	/**
	 * Extracts user, department and project IDs from a template's constants.
	 *
	 * @param array $template A single template data array.
	 * @return array{
	 *     userIds: list<int>,
	 *     departmentIds: list<int>,
	 *     recursiveDepartmentIdsFromConstant: list<int>,
	 *     projectIds: list<int>
	 * }
	 */
	private function extractIdsFromConstants(array $template): array
	{
		if (!is_null($template['SYSTEM_CODE']) || empty($template['CONSTANTS']))
		{
			return [
				'userIds' => [],
				'departmentIds' => [],
				'recursiveDepartmentIdsFromConstant' => [],
				'projectIds' => [],
			];
		}

		$userIds = [];
		$departmentIds = [];
		$recursiveDepartmentIds = [];
		$projectIds = [];

		$documentType = $this->getTemplateDocumentType($template);

		$constants = $this->setupTemplateService->normalizeConstantsByTemplate(
			is_array($template['TEMPLATE'] ?? null) ? $template['TEMPLATE'] : [],
			(array)($template['CONSTANTS'] ?? []),
		);

		foreach ($constants as $constantInfo)
		{
			if (
				($constantInfo['Type'] ?? '') === 'project'
				&& !empty($template['ACTIVATED_BY'])
				&& !empty($template['ACTIVATED_AT'])
			)
			{
				foreach ((array)($constantInfo['Default'] ?? []) as $value)
				{
					if (!is_int($value) && !is_string($value))
					{
						continue;
					}

					$projectId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
					if ($projectId !== false)
					{
						$projectIds[] = $projectId;
					}
				}
			}

			if (($constantInfo['Type'] ?? '') !== FieldType::USER)
			{
				continue;
			}

			foreach ((array)($constantInfo['Default'] ?? []) as $value)
			{
				$this->parseConstantValue(
					(string)$value,
					$documentType,
					$userIds,
					$departmentIds,
					$recursiveDepartmentIds,
				);
			}
		}

		return [
			'userIds' => array_unique($userIds),
			'departmentIds' => array_unique($departmentIds),
			'recursiveDepartmentIdsFromConstant' => array_unique($recursiveDepartmentIds),
			'projectIds' => array_values(array_unique($projectIds)),
		];
	}

	/**
	 * Parses a single constant value string and populates the ID arrays by reference.
	 *
	 * @param string $value The value to parse (e.g., 'user_1', 'group_hrr123', '[123]', '[HR456]').
	 * @param array $documentType
	 * @param list<int> &$userIds Passed by reference.
	 * @param list<int> &$departmentIds Passed by reference.
	 * @param list<int> &$recursiveDepartmentIds Passed by reference.
	 */
	private function parseConstantValue(
		string $value,
		array $documentType,
		array &$userIds,
		array &$departmentIds,
		array &$recursiveDepartmentIds,
	): void
	{
		if (str_starts_with($value, 'user'))
		{
			$extractedIds = CBPHelper::extractUsers($value, $documentType);
			if (!empty($extractedIds))
			{
				$userIds = [...$userIds, ...$extractedIds];
			}
		}

		if (str_starts_with($value, 'group'))
		{
			if (preg_match('#^group_hr(r?)(\d+)$#', $value, $matches))
			{
				$id = (int)$matches[2];
				$isRecursive = !empty($matches[1]);

				$departmentIds[] = $id;
				if ($isRecursive)
				{
					$recursiveDepartmentIds[] = $id;
				}
			}
		}

		if (preg_match_all('/\[(\d+)]/', $value, $matches))
		{
			$extractedIds = array_map('intval', $matches[1]);
			if (!empty($extractedIds))
			{
				$userIds = [...$userIds, ...$extractedIds];
			}
		}

		if (preg_match_all('/\[HR(R?)(\d+)]/i', $value, $matches, PREG_SET_ORDER))
		{
			foreach ($matches as $match)
			{
				$id = (int)$match[2];
				$isRecursive = !empty($match[1]);

				$departmentIds[] = $id;
				if ($isRecursive)
				{
					$recursiveDepartmentIds[] = $id;
				}
			}
		}
	}

	/**
	 * Returns the workflow document type triple [MODULE_ID, ENTITY, DOCUMENT_TYPE] for a template row.
	 *
	 * DOCUMENT_TYPE arrives in two shapes depending on the source: a scalar code from the grid ORM
	 * query, or an already-expanded triple array from the copy-and-start raw fields. Both are
	 * normalized to a flat triple; otherwise a nested array is passed as the third element and
	 * CBPHelper::parseDocumentId() rejects it as an empty documentId.
	 *
	 * @param array $template
	 * @return array{0: mixed, 1: mixed, 2: mixed}
	 */
	private function getTemplateDocumentType(array $template): array
	{
		$documentType = $template['DOCUMENT_TYPE'] ?? null;

		if (is_array($documentType))
		{
			return array_values($documentType);
		}

		return [$template['MODULE_ID'] ?? null, $template['ENTITY'] ?? null, $documentType];
	}

	/**
	 * Fetches user data for a given list of user IDs.
	 *
	 * @param list<int> $userIds
	 * @return array<int, array> A map of [userId => userData].
	 */
	private function fetchUsersByIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$usersList = UserTable::query()
			->setSelect([
				'ID',
				'PERSONAL_PHOTO',
				'NAME',
				'SECOND_NAME',
				'LAST_NAME',
			])
			->whereIn('ID', $userIds)
			->fetchAll()
		;

		$userMap = [];
		foreach ($usersList as $user)
		{
			$userMap[$user['ID']] = $user;
		}

		return $userMap;
	}

	/**
	 * Fetches department names by their IDs.
	 *
	 * @param list<int> $departmentIds
	 * @return array<int, string> Map of [departmentId => departmentName].
	 */
	private function fetchDepartmentsByIds(array $departmentIds): array
	{
		if (empty($departmentIds))
		{
			return [];
		}

		$departmentCollection = NodeDataBuilder::createWithFilter(
			new NodeFilter(
				idFilter: IdFilter::fromIds($departmentIds),
				entityTypeFilter: NodeTypeFilter::createForDepartment(),
				direction: Direction::ROOT,
				depthLevel: DepthLevel::NONE,
			),
		)
			->getAll()
		;

		/**
		 * @var $departmentNameByIdMap array<int, string>
		 */
		$departmentNameByIdMap = [];
		foreach ($departmentCollection as $department)
		{
			$departmentNameByIdMap[$department->id] = $department->name;
		}

		return $departmentNameByIdMap;
	}

	/**
	 * Fetches all child department IDs and returns them as a map.
	 *
	 * @param list<int> $parentDepartmentIds
	 * @return array<int, list<int>> A map of [parentId => [childId1, childId2, ...]].
	 */
	private function fetchChildDepartmentMap(array $parentDepartmentIds): array
	{
		/**
		 * @var $childDepartmentIdsByIdMap array<int, list<int>>
		 */
		$childDepartmentIdsByIdMap = [];
		foreach ($parentDepartmentIds as $id)
		{
			$childDepartmentCollection = NodeDataBuilder::createWithFilter(
				new NodeFilter(
					idFilter: IdFilter::fromId($id),
					entityTypeFilter: NodeTypeFilter::createForDepartment(),
					direction: Direction::CHILD,
					depthLevel: DepthLevel::FULL,
				),
			)
				->getAll()
			;

			if (!$childDepartmentCollection->empty())
			{
				if (empty($childDepartmentIdsByIdMap[$id] ?? null))
				{
					$childDepartmentIdsByIdMap[$id] = [];
				}

				foreach ($childDepartmentCollection as $childDepartment)
				{
					$departmentId = filter_var($childDepartment->id, FILTER_VALIDATE_INT, [
						'options' => [
							'min_range' => 0,
						],
					]);

					if (!$departmentId)
					{
						continue;
					}

					$childDepartmentIdsByIdMap[$id][] = $departmentId;
				}
			}
		}

		return $childDepartmentIdsByIdMap;
	}

	/**
	 * @param array $templates The original templates array.
	 * @param array $idMapByTemplate A map of IDs separated by type for each template.
	 * @param array $users A map of [userId => userData].
	 * @param array $departments A map of [departmentId => departmentName].
	 * @param array $childDepartmentMap A map of [parentId => [childIds...]].
	 * @param array $templateChatsMap A map of [templateId => [[chatId, chatName], ...]].
	 * @return array The final, enriched templates array.
	 */
	private function attachRelatedDataToTemplates(
		array $templates,
		array $idMapByTemplate,
		array $users,
		array $departments,
		array $childDepartmentMap,
		array $templateChatsMap,
	): array
	{
		$result = [];
		foreach ($templates as $template)
		{
			// the TEMPLATE body was only needed to detect create-bot activities; drop it so the
			// (potentially large) workflow tree is not carried into every grid row.
			unset($template['TEMPLATE']);

			$templateId = (int)$template['ID'];
			$idMap = $idMapByTemplate[$templateId] ?? null;

			if (!$idMap || empty($template['ACTIVATED_BY']))
			{
				$result[] = $template;

				continue;
			}

			if (!empty($idMap['usedByUserIds']))
			{
				$template['USED_BY_USERS_DATA'] = [];
				foreach ($idMap['usedByUserIds'] as $userId)
				{
					if (isset($users[$userId]))
					{
						$template['USED_BY_USERS_DATA'][] = $users[$userId];
					}
				}
			}

			$finalDepartmentIds = $idMap['departmentIds'];
			if (!empty($idMap['recursiveDepartmentIds']))
			{
				$finalDepartmentIds = [...$finalDepartmentIds, ...$idMap['recursiveDepartmentIds']];
				foreach ($idMap['recursiveDepartmentIds'] as $parentId)
				{
					if (isset($childDepartmentMap[$parentId]))
					{
						$finalDepartmentIds = [...$finalDepartmentIds, ...$childDepartmentMap[$parentId]];
					}
				}
			}

			$uniqueDepartmentIds = array_values(array_unique($finalDepartmentIds));
			if (!empty($uniqueDepartmentIds))
			{
				$template['DEPARTMENTS'] = [];
				foreach ($uniqueDepartmentIds as $departmentId)
				{
					if (isset($departments[$departmentId]))
					{
						$template['DEPARTMENTS'][$departmentId] = $departments[$departmentId];
					}
				}
			}

			if (isset($idMap['launchedById'], $users[$idMap['launchedById']]))
			{
				$template['LAUNCHED_BY_USER_DATA'] = $users[$idMap['launchedById']];
			}

			if (isset($idMap['ragFilesStatuses']) && !empty($idMap['ragFilesStatuses']))
			{
				$template['RAG_FILES_STATUS'] = $idMap['ragFilesStatuses'];
			}

			if (!empty($templateChatsMap[$templateId]))
			{
				$template['CHATS'] = $templateChatsMap[$templateId];
			}

			$result[] = $template;
		}

		return $result;
	}

	private function getUserIdFromString(string|int|null $value): ?int
	{
		if (empty($value))
		{
			return null;
		}

		$userId = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		return $userId === false ? null : $userId;
	}

	private function getRagFilesStatuses(array $template): ?KnowledgeBaseFileStatusDtoCollection
	{
		if (
			!is_null($template['SYSTEM_CODE'])
			|| empty($template['CONSTANTS'])
			|| !Loader::includeModule('rag')
		)
		{
			return null;
		}

		$cache = ServiceLocator::getInstance()->get(KnowledgeBaseFileCacheService::class);
		foreach ((array)$template['CONSTANTS'] as $constantInfo)
		{
			if (
				$constantInfo['Type'] !== RagKnowledgeBaseType::getType()
				|| empty($constantInfo['Default'])
			)
			{
				continue;
			}

			foreach ((array)($constantInfo['Default'] ?? []) as $value)
			{
				if ($cacheItem = $cache->getCacheInfoUploadFiles($value))
				{
					if ($cacheItem->getStatus() == FileStatus::Success)
					{
						continue;
					}

					return $cacheItem;
				}

				$result = ServiceLocator::getInstance()
					->get(KnowledgeBaseService::class)
					->getInfo($value)
				;

				if (!$result instanceof KnowledgeBaseGetInfoResult)
				{
					continue;
				}

				$info = ServiceLocator::getInstance()
					->get(KnowledgeBaseFileService::class)
					->getInfoUploadFiles($result->info->id)
				;

				if ($status = $info->getStatus())
				{
					if (!in_array($status, [FileStatus::Uploading, FileStatus::Processing]))
					{
						$cache->setCacheInfoUploadFiles($value, $info);
					}

					if ($status != FileStatus::Success)
					{
						return $info;
					}
				}
			}
		}

		return null;
	}

	private function fetchRagFileNameByFileIds(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$fileList = FileTable::getList([
			'select' => [
				'ID',
				'ORIGINAL_NAME',
			],
			'filter' => [
				'ID' => $ids,
			],
		])->fetchAll();

		$list = [];
		foreach ($fileList as $file)
		{
			if (empty($file['ID']))
			{
				continue;
			}

			$list[$file['ID']] = $file['ORIGINAL_NAME'] ?? null;
		}

		return $list;
	}

	private function fileRagFileNamesToIdMapByTemplate(array &$dMapByTemplate, array $fileNameList): void
	{
		foreach ($dMapByTemplate as $templateId => $item)
		{
			$ragFilesStatuses = $item['ragFilesStatuses'] ?? null;
			if (!$ragFilesStatuses instanceof KnowledgeBaseFileStatusDtoCollection)
			{
				continue;
			}

			foreach ($ragFilesStatuses->getAll() as $file)
			{
				$file->fileName = $fileNameList[$file->fileId] ?? null;
			}
			$dMapByTemplate[$templateId]['ragFilesStatuses'] = $ragFilesStatuses;
		}
	}

	/**
	 * Builds the chatbots list for each template. Only direct chat bots are exposed,
	 * group chats the bot merely participates in are intentionally excluded.
	 *
	 * @param array<int, list<int>> $templateBotIdMap [templateId => [botUserId, ...]]
	 * @param array $users [userId => userData]
	 * @return array<int, list<array{chatId: int, chatName: string}>>
	 */
	private function prepareTemplateChatsMap(array $templateBotIdMap, array $users): array
	{
		$chatsMap = [];

		foreach ($templateBotIdMap as $templateId => $botIds)
		{
			foreach ($botIds as $botId)
			{
				$bot = $users[$botId] ?? null;
				$botName = $bot['NAME'] ?? null;

				if (empty($botName))
				{
					continue;
				}

				$chatsMap[$templateId][] = [
					'chatId' => $botId,
					'chatName' => $botName,
				];
			}
		}

		return $chatsMap;
	}

	private function applyFilterToQuery(Query $query, array $filterData): void
	{
		if (empty($filterData))
		{
			return;
		}

		$filter = $this->grid?->getFilter();
		if (!$filter instanceof AiAgentsFilter)
		{
			return;
		}

		// Whitelist is the set of declared filter fields, not the visible grid columns:
		// a filter-only field (AGENT_TEMPLATE) is filterable without being a grid column.
		$fieldsWhiteList = $filter->getFilterSettings()?->getWhiteList() ?? [];

		foreach ($filterData as $filterId => $filterValue)
		{
			if (!in_array($filterId, $fieldsWhiteList, true))
			{
				continue;
			}

			$this->addWhereToQuery($query, $filterId, $filterValue);
		}
	}

	private function addWhereToQuery(Query $query, int|string $filterId, mixed $filterValue): void
	{
		match ($filterId)
		{
			AiAgentsFilterSettings::LAUNCHED_BY_FIELD => $this->addLaunchedByQueryFilter($query, $filterValue),
			AiAgentsFilterSettings::AGENT_TEMPLATE_FIELD => $this->addAgentTemplateQueryFilter($query, $filterValue),
			AiAgentsFilterSettings::IS_ACTIVE_FIELD => $this->addIsActiveQueryFilter($query, $filterValue),
			default => null,
		};
	}

	private function addLaunchedByQueryFilter(Query $query, mixed $filterValue): void
	{
		if (!is_array($filterValue))
		{
			return;
		}

		$userIds = [];

		foreach ($filterValue as $rawUserId)
		{
			$userId = $this->getUserIdFromString($rawUserId);

			if ($userId)
			{
				$userIds[] = $userId;
			}
		}


		if (empty($userIds))
		{
			return;
		}

		$query->where(
			Query::filter()
				->logic('or')
				->whereIn('ACTIVATED_BY', $userIds)
				->where('ACTIVATED_AT', null),
		);
	}

	private function addAgentTemplateQueryFilter(Query $query, mixed $filterValue): void
	{
		if (!is_array($filterValue))
		{
			return;
		}

		$systemCodes = [];
		foreach ($filterValue as $rawSystemCode)
		{
			if (is_string($rawSystemCode) && $rawSystemCode !== '')
			{
				$systemCodes[] = $rawSystemCode;
			}
		}

		if (empty($systemCodes))
		{
			return;
		}

		// The link "system template -> its launched copies" lives in LaunchedCopyQuery; only the
		// SYSTEM_CODE branch (match the system row itself) is the filter's own business.
		$originLink = LaunchedCopyQuery::joinOriginLink($query, $systemCodes);

		$query->where(
			Query::filter()
				->logic('or')
				->whereIn('SYSTEM_CODE', $systemCodes)
				->where($originLink),
		);

		// The LEFT JOIN to the one-to-many origin settings can duplicate a template row;
		// collapse duplicates by grouping on the PK. Applied only here, so the base grid
		// query keeps no GROUP BY. ID is the PK, so other selected columns stay valid under
		// GROUP BY on both MySQL and PostgreSQL.
		$query->setGroup(['ID']);
	}

	/**
	 * What counts as an active agent is defined once, in LaunchedCopyQuery: the same definition
	 * serves the existing-runs warning, so the warning and this filter can never disagree.
	 */
	private function addIsActiveQueryFilter(Query $query, mixed $filterValue): void
	{
		match ($filterValue)
		{
			'Y' => LaunchedCopyQuery::applyActive($query),
			'N' => LaunchedCopyQuery::applyInactive($query),
			default => null,
		};
	}
}
