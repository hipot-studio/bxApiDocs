<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\ClickEventBuilder;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\ClickEventWrapper;
use Bitrix\Crm\Copilot\CallAssessment\BiReportButton;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\CallListGrid;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Router\ResponseHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\UI;

class CrmCopilotCallAssessmentCallListComponent extends Base
{
	private const DEFAULT_PAGE_SIZE = 20;
	private const GRID_ID = 'crm_copilot_call_assessment_call_list_grid';
	private const FILTER_ID = self::GRID_ID;
	private const MAX_ASSESSMENT_SCRIPTS_COUNT = 200;

	public function executeComponent(): void
	{
		if (!$this->canShow())
		{
			$this->redirectToCrmRoot();
		}

		$assessmentId = (int)($this->arParams['ASSESSMENT_SETTING_ID'] ?? 0);
		if ($assessmentId <= 0)
		{
			ResponseHelper::showPageNotFound();
		}

		$assessment = CopilotCallAssessmentController::getInstance()->getById($assessmentId);
		if (!$assessment)
		{
			ResponseHelper::showPageNotFound();
		}

		Container::getInstance()->getLocalization()->loadMessages();

		$assessmentMap = $this->loadAssessmentMap();

		$this->seedAssessmentFilter($assessmentId);
		$filterConditions = $this->buildFilterConditions((new FilterOptions(self::FILTER_ID))->getFilter());

		$grid = $this->buildGrid($filterConditions, $assessmentMap);

		$this->arResult['ASSESSMENT_ID'] = $assessment->getId();
		$this->arResult['TITLE'] = Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_TITLE');
		$this->arResult['BI_REPORT_LABEL'] = Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_BI_REPORT');
		$this->arResult['BI_REPORT_HINT'] = Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_BI_REPORT_HINT');
		$this->arResult['GRID'] = $grid;
		$this->arResult['GRID_ID'] = self::GRID_ID;
		$this->arResult['FILTER_ID'] = self::FILTER_ID;
		$this->arResult['FILTER_FIELDS'] = $this->getFilterFields($assessmentMap);

		$this->includeComponentTemplate();
	}

	private function canShow(): bool
	{
		return Bitrix24Manager::isFeatureEnabled(AIManager::AI_COPILOT_FEATURE_NAME)
			&& AIManager::isAiCallProcessingEnabled()
			&& AIManager::isCallScoringV2Enabled()
			&& Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canRead()
		;
	}

	private function redirectToCrmRoot(): never
	{
		LocalRedirect(Container::getInstance()->getRouter()->getRoot());
	}

	/**
	 * @return array<int, array{title: string, low: int, high: int}>
	 */
	private function loadAssessmentMap(): array
	{
		$assessments = CopilotCallAssessmentController::getInstance()->getList([
			'select' => [
				'ID',
				'TITLE',
				'LOW_BORDER',
				'HIGH_BORDER',
			],
			'order' => [
				'TITLE' => 'ASC',
			],
			'limit' => self::MAX_ASSESSMENT_SCRIPTS_COUNT,
		]);

		$map = [];
		foreach ($assessments as $assessment)
		{
			$map[$assessment->getId()] = [
				'title' => $assessment->getTitle(),
				'low' => $assessment->getLowBorder(),
				'high' => $assessment->getHighBorder(),
			];
		}

		return $map;
	}

	private function seedAssessmentFilter(int $assessmentIdFromUrl): void
	{
		if (Context::getCurrent()->getRequest()->get('bxajaxid') !== null)
		{
			return;
		}

		$options = new FilterOptions(self::FILTER_ID);
		$savedIds = $this->extractIntIds(($options->getFilter())['ASSESSMENT_SETTING_ID'] ?? null);
		if ($savedIds === [$assessmentIdFromUrl])
		{
			return;
		}

		$fields = ['ASSESSMENT_SETTING_ID' => [$assessmentIdFromUrl]];
		$options->setupDefaultFilter($fields, array_keys($fields));
	}

	private function buildGrid(array $filterConditions, array $assessmentMap): CallListGrid
	{
		$settings = new Settings([
			'ID' => self::GRID_ID,
			'NAV_PARAM_NAME' => 'page',
			'PAGE_SIZE' => self::DEFAULT_PAGE_SIZE,
		]);

		$grid = new CallListGrid($settings, $filterConditions);

		$totalCount = AiQualityAssessmentTable::getCount($grid->getOrmFilter() ?? []);
		$grid->getPagination()?->setRecordCount($totalCount);
		$grid->processRequest();

		$rawRows = AiQualityAssessmentTable::getList($grid->getOrmParams())->fetchAll();
		$grid->setRawRows($this->injectAssessmentData($rawRows, $assessmentMap));

		return $grid;
	}

	private function injectAssessmentData(array $rawRows, array $assessmentMap): array
	{
		foreach ($rawRows as &$row)
		{
			$id = (int)($row['ASSESSMENT_SETTING_ID'] ?? 0);
			$data = $assessmentMap[$id] ?? null;
			$row['SCRIPT_TITLE'] = $data['title'] ?? '';
			$row['LOW_BORDER'] = $data['low'] ?? CallAssessmentItem::LOW_BORDER_DEFAULT;
			$row['HIGH_BORDER'] = $data['high'] ?? CallAssessmentItem::HIGH_BORDER_DEFAULT;
		}
		unset($row);

		return $rawRows;
	}

	private function buildFilterConditions(array $filter): array
	{
		$conditions = [];

		$assessmentIds = $this->extractIntIds($filter['ASSESSMENT_SETTING_ID'] ?? null);
		if (!empty($assessmentIds))
		{
			$conditions['@ASSESSMENT_SETTING_ID'] = $assessmentIds;
		}

		$userIds = $this->extractUserIds($filter['RATED_USER_ID'] ?? null);
		if (!empty($userIds))
		{
			$conditions['@RATED_USER_ID'] = $userIds;
		}

		$dateKeyMap = [
			'>=CALL_DATE' => '>=ACTIVITY.START_TIME',
			'<=CALL_DATE' => '<=ACTIVITY.START_TIME',
		];
		foreach (DateType::getLogicFilter($filter, []) as $key => $value)
		{
			$conditions[$dateKeyMap[$key] ?? $key] = $value;
		}

		foreach (NumberType::getLogicFilter($filter, []) as $key => $value)
		{
			$conditions[$key] = $value;
		}

		return $conditions;
	}

	private function extractIntIds(mixed $value): array
	{
		if ($value === null || $value === '')
		{
			return [];
		}

		$values = is_array($value) ? $value : [$value];
		$ids = array_map(static fn($v) => (int)$v, $values);

		return array_values(
			array_unique(
				array_filter($ids, static fn($id) => $id > 0),
			),
		);
	}

	private function extractUserIds(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$ids = [];
		foreach ($value as $item)
		{
			if (is_array($item))
			{
				$entityId = $item['id'] ?? $item['entityId'] ?? null;
				if ($entityId !== null)
				{
					$ids[] = (int)$entityId;
				}
			}
			else
			{
				$ids[] = (int)$item;
			}
		}

		return array_values(array_filter($ids, static fn($id) => $id > 0));
	}

	private function getFilterFields(array $assessmentMap): array
	{
		return [
			[
				'id' => 'ASSESSMENT_SETTING_ID',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_FILTER_SCRIPT'),
				'type' => 'list',
				'items' => $this->buildAssessmentItems($assessmentMap),
				'default' => true,
				'params' => [
					'multiple' => 'Y',
				],
			],
			[
				'id' => 'RATED_USER_ID',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_FILTER_MANAGER'),
				'type' => 'entity_selector',
				'default' => true,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => ['inviteEmployeeLink' => false],
							],
							['id' => 'department'],
						],
					],
				],
			],
			[
				'id' => 'CALL_DATE',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_FILTER_DATE'),
				'type' => 'date',
				'default' => true,
			],
			[
				'id' => 'ASSESSMENT',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_CALL_LIST_FILTER_SCORE'),
				'type' => 'number',
				'default' => true,
			],
		];
	}

	private function buildAssessmentItems(array $assessmentMap): array
	{
		$items = [];
		foreach ($assessmentMap as $id => $data)
		{
			$items[(string)$id] = $data['title'];
		}

		return $items;
	}

	protected function getToolbarParameters(): array
	{
		$buttons = [];

		$biReportOnClick = ClickEventWrapper::wrap(
			BiReportButton::getInstance()->getOnClickJsCode('crm_copilot_call_assessment_calllist'),
			ClickEventBuilder::BiReport,
		);
		if ($biReportOnClick !== null)
		{
			$buttons[UI\Toolbar\ButtonLocation::RIGHT][] = new UI\Buttons\Button([
				'color' => UI\Buttons\Color::PRIMARY,
				'style' => UI\Buttons\AirButtonStyle::FILLED,
				'text'  => $this->arResult['BI_REPORT_LABEL'] ?? '',
				'icon'  => null,
				'attributes' => [
					'title' => $this->arResult['BI_REPORT_HINT'] ?? '',
				],
				'onclick' => $biReportOnClick,
			]);
		}

		return array_merge(parent::getToolbarParameters(), [
			'isWithFavoriteStar' => false,
			'hideBorder' => true,
			'buttons' => $buttons,
			'filter' => [
				'FILTER_ID' => self::FILTER_ID,
				'GRID_ID' => self::GRID_ID,
				'FILTER' => $this->arResult['FILTER_FIELDS'] ?? [],
				'FILTER_PRESETS' => [],
				'ENABLE_LIVE_SEARCH' => false,
				'ENABLE_LABEL' => true,
				'DISABLE_SEARCH' => true,
			],
		]);
	}
}
