<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents;

use CBPHelper;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Engine\Response\Converter;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;


/**
 * Helper class that encapsulates grid creation and data fetching logic.
 */
class AiAgentsGridHelper
{
	private const GRID_ID = 'BIZPROC_AI_AGENTS_GRID';
	private const AI_SECTION_ID = 'AI_AGENT';
	private const DEFAULT_PAGE_SIZE = 20;

	private string $navParamName;
	private ?AiAgentsGrid $grid = null;

	public function __construct()
	{
		$this->navParamName = self::GRID_ID . '_nav';

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
			'MODE' => $arParams['EXPORT_TYPE'] ?? 'html'
		]);

		$this->grid = new AiAgentsGrid($settings);

		$this->grid->setTotalCountCalculator(function ()
		{
			$query = $this->getAiAgentsTemplatesQuery();
			return $query->fetchCollection()?->count();
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
		$filterOptions = new \Bitrix\Main\UI\Filter\Options($this->getGridId());
		$filterData = $filterOptions->getFilter();

		$limit = $ormParams['limit'] ?? self::DEFAULT_PAGE_SIZE;
		$offset = $ormParams['offset'] ?? 0;

		return $this->getGridData($limit, $offset, $filterData);
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
			]
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
		$templates = $this->getAiAgentsTemplates($limit, $offset);

		if (!$templates)
		{
			return [];
		}

		return $templates;
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
			])
			->registerRuntimeField(
				'SECTION',
				new \Bitrix\Main\ORM\Fields\Relations\Reference(
					'SECTION',
					WorkflowTemplateSectionTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID')
				)
			)
			->where('SECTION.SECTION_ID', self::AI_SECTION_ID)
			->setOrder([
				'ACTIVE' => 'DESC',
				'ACTIVATED_AT' => 'DESC',
				'ID' => 'DESC',
			])
		;

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

	private function getAiAgentsTemplates(int $limit, int $offset): array
	{
		$query = $this->getAiAgentsTemplatesQuery($limit, $offset);
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

		foreach ($templates as $template)
		{
			$templateId = (int)$template['ID'];
			$launchedById = $this->getUserIdFromString($template['ACTIVATED_BY']);
			if ($launchedById)
			{
				$allUserIds[] = $launchedById;
			}

			$extractedIds = $this->extractIdsFromConstants($template);

			$allUserIds = [...$allUserIds, ...$extractedIds['userIds']];
			$allDepartmentIds = [...$allDepartmentIds, ...$extractedIds['departmentIds']];
			$allRecursiveDepartmentIds = [...$allRecursiveDepartmentIds, ...$extractedIds['recursiveDepartmentIdsFromConstant']];

			$idMapByTemplate[$templateId] = [
				'launchedById' => $launchedById,
				'usedByUserIds' => $extractedIds['userIds'],
				'departmentIds' => $extractedIds['departmentIds'],
				'recursiveDepartmentIds' => $extractedIds['recursiveDepartmentIdsFromConstant'],
			];
		}

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

		return $this->attachRelatedDataToTemplates($templates, $idMapByTemplate, $users, $departments, $childDepartmentMap);
	}

	/**
	 * Extracts user and department IDs from a template's constants.
	 *
	 * @param array $template A single template data array.
	 * @return array{
	 *     userIds: list<int>,
	 *     departmentIds: list<int>,
	 *     recursiveDepartmentIds: list<int>
	 * }
	 */
	private function extractIdsFromConstants(array $template): array
	{
		if (!is_null($template['SYSTEM_CODE']) || empty($template['CONSTANTS']))
		{
			return ['userIds' => [], 'departmentIds' => [], 'recursiveDepartmentIdsFromConstant' => []];
		}

		$userIds = [];
		$departmentIds = [];
		$recursiveDepartmentIds = [];

		$documentType = [$template['MODULE_ID'], $template['ENTITY'], $template['DOCUMENT_TYPE']];

		foreach ((array)$template['CONSTANTS'] as $constantInfo)
		{
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
					$recursiveDepartmentIds
				);
			}
		}

		return [
			'userIds' => array_unique($userIds),
			'departmentIds' => array_unique($departmentIds),
			'recursiveDepartmentIdsFromConstant' => array_unique($recursiveDepartmentIds),
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
		array  $documentType,
		array  &$userIds,
		array  &$departmentIds,
		array  &$recursiveDepartmentIds
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
			->fetchAll();

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
			)
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
				)
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
	 * @return array The final, enriched templates array.
	 */
	private function attachRelatedDataToTemplates(
		array $templates,
		array $idMapByTemplate,
		array $users,
		array $departments,
		array $childDepartmentMap
	): array
	{
		$result = [];
		foreach ($templates as $template)
		{
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

			$result[] = $template;
		}

		return $result;
	}

	private function getUserIdFromString(string | int | null $value): ?int
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
}
