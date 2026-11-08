<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Badge\Type\CopilotCallAssessmentStatus;
use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Component\EntityList\BadgeBuilder;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\AiQualityAssessment\RatingCalculator;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\ClickEventBuilder;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\ClickEventWrapper;
use Bitrix\Crm\Copilot\CallAssessment\BiReportButton;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentAvailabilityController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentClientTypeController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AvailabilityType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AvailabilityWeekdayType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Filter\ListFilter;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Integration\BizProc\CallAssessmentAiAgent;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\LayoutSettings;
use Bitrix\Crm\WebForm\Internals\PageNavigation;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Grid\Options;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use Bitrix\UI;

class CrmCopilotCallAssessmentListComponent extends Base
{
	protected const DEFAULT_PAGE_SIZE = 20;

	private string $navParamName = 'page';
	private ?Options $gridOptions = null;
	private ?PageNavigation $pageNavigation = null;
	private array|string|null $defaultDateTimeFormat = null;
	private array $currentAvailableAssessmentIds = [];
	private bool $isV2 = false;

	public function executeComponent(): void
	{
		if (!Bitrix24Manager::isFeatureEnabled(AIManager::AI_COPILOT_FEATURE_NAME))
		{
			$this->includeComponentTemplate('restrictions');

			return;
		}

		if (
			!AIManager::isAiCallProcessingEnabled()
			|| !Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canRead()
		)
		{
			$this->showError();

			return;
		}

		Container::getInstance()->getLocalization()->loadMessages();

		$this->isV2 = AIManager::isCallScoringV2Enabled();

		if (Feature::enabled(Feature\CopilotCallAssessmentAvailability::class))
		{
			$this->currentAvailableAssessmentIds = CopilotCallAssessmentAvailabilityController::getInstance()->getCurrentAvailableAssessmentIds();
		}

		$this->arResult['SORT'] = $this->getOrder();
		$this->arResult['GRID_ID'] = $this->getGridId();
		$this->arResult['PAGE_NAVIGATION'] = $this->getPageNavigation();
		$this->arResult['ROWS'] = $this->getRows();
		$this->arResult['COLUMNS'] = $this->getColumns();
		$this->arResult['FILTER'] = $this->getFilter();

		$this->includeComponentTemplate();
	}

	private function showError(): void
	{
		$this->getApplication()->IncludeComponent(
			'bitrix:ui.info.error',
			'',
			[
				'TITLE' => Loc::getMessage('CRM_COMMON_ERROR_ACCESS_DENIED'),
				'DESCRIPTION' => '',
			],
		);
	}

	private function getRows(): array
	{
		$callAssessmentController = CopilotCallAssessmentController::getInstance();
		$callAssessmentsCollection = $callAssessmentController->getList([
			'filter' => $this->getFilterConditions(),
			'order' => $this->getOrder(),
			'offset' => $this->getPageNavigation()->getOffset(),
			'limit' => $this->getPageNavigation()->getLimit(),
		]);

		$fieldsData = $this->getPreparedFieldsData($callAssessmentsCollection);

		$rows = [];
		foreach ($callAssessmentsCollection as $callAssessment)
		{
			$rule['ID'] = $this->getField('ID', $callAssessment);
			$rule['TITLE'] = $this->getField('TITLE', $callAssessment, $fieldsData);
			$rule['CLIENT'] = $this->getField('CLIENT', $callAssessment, $fieldsData);
			$rule['CALL_TYPE'] = $this->getField('CALL_TYPE', $callAssessment, $fieldsData);
			$rule['IS_ENABLED'] = $this->getField('IS_ENABLED', $callAssessment);
			if ($this->isV2)
			{
				$rule['MATCH'] = '<div class="crm-copilot-call-assessment-list--field-wrapper">'
					. $this->getMatchField($callAssessment, $fieldsData)
					. '</div>';
			}
			else
			{
				$rule['ASSESSMENT_AVG'] = $this->getField('ASSESSMENT_AVG', $callAssessment, $fieldsData);
			}
			// $rule['INSPECTOR'] = $this->getField('INSPECTOR', $callAssessment, $fieldsData);
			$rule['PROMPT'] = $this->getField('PROMPT', $callAssessment);
			if ($this->needShowGistColumn())
			{
				$rule['GIST'] = $this->getField('GIST', $callAssessment);
			}
			$rule['MODIFIED'] = $this->getField('MODIFIED', $callAssessment, $fieldsData);

			$rows[] = [
				'id' => $callAssessment['ID'],
				'columns' => $rule,
				'actions' => $this->getRowActions($callAssessment['ID']),
			];
		}
		unset($rule);

		return $rows;
	}

	private function getRowActions(int $id): array
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			return [];
		}

		$sliderWidth = AIManager::isCallScoringV2Enabled() ? 729 : 700;

		return [
			[
				'TEXT' => Loc::getMessage('CRM_COMMON_ACTION_EDIT'),
				'ONCLICK' => 'BX.Crm.Router.openSlider("' . $this->getDetailsUri($id) . '", {width: ' . $sliderWidth . ', cacheable: false });',
				'DEFAULT' => true,
			],
			[
				'TEXT' => Loc::getMessage('CRM_COMMON_ACTION_COPY'),
				'HREF' => $this->getDetailsUri($id)->addParams(['copy' => 'Y']),
			],
			[
				'TEXT' => Loc::getMessage('CRM_COMMON_ACTION_DELETE'),
				'ONCLICK' => "BX.Event.EventEmitter.emit('BX.Crm.Copilot.CallAssessment:onClickDelete', {'id':'$id'})",
			],
		];
	}

	private function getFilterConditions(): array
	{
		$filterOptions = new \Bitrix\Main\UI\Filter\Options($this->getGridId());
		$gridFilter = $filterOptions->getFilter();
		$listFilter = new ListFilter(CCrmOwnerType::Undefined, $this->getFilterFields());

		$conditions = [];

		$findText = null;
		if (!empty($gridFilter['FIND']))
		{
			$findText = $gridFilter['FIND'];
			unset($gridFilter['FIND']);
		}
		$listFilter->prepareListFilter($conditions, $gridFilter);

		if (!empty($gridFilter['CLIENT_TYPE_ID']))
		{
			array_walk($gridFilter['CLIENT_TYPE_ID'], static fn($clientTypeId) => (int)$clientTypeId);
			$conditions['@CLIENT_TYPES.CLIENT_TYPE_ID'] = $gridFilter['CLIENT_TYPE_ID'];
		}

		if ($findText)
		{
			$helper = Application::getConnection()->getSqlHelper();
			$findText = str_replace('%', '\%', $findText);
			$conditions['%=TITLE'] = '%' . $helper->forSql($findText) . '%';
		}

		return $conditions;
	}

	private function getFilter(): array
	{
		return $this->getFilterFields();
	}

	private function getFilterFields(): array
	{
		return [
			'ID' => [
				'id' => 'ID',
				'name' => 'ID',
				'type' => 'string',
			],
			'TITLE' => [
				'id' => 'TITLE',
				'name' => Loc::getMessage('CRM_COMMON_TITLE'),
				'type' => 'string',
				'default' => true,
			],
			'CLIENT_TYPE.CLIENT_TYPE_ID' => [
				'id' => 'CLIENT_TYPE_ID',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_CLIENT'),
				'type' => 'list',
				'items' => [
					ClientType::NEW->value => ClientType::getTitle(ClientType::NEW->value),
					ClientType::IN_WORK->value => ClientType::getTitle(ClientType::IN_WORK->value),
					ClientType::RETURN_CUSTOMER->value => ClientType::getTitle(ClientType::RETURN_CUSTOMER->value),
					ClientType::REPEATED_APPROACH->value => ClientType::getTitle(ClientType::REPEATED_APPROACH->value),
					ClientType::ANY->value => ClientType::getTitle(ClientType::ANY->value),
				],
				'params' => [
					'multiple' => 'Y',
				],
			],
			'CALL_TYPE' => [
				'id' => 'CALL_TYPE',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_CALL_TYPE'),
				'type' => 'list',
				'items' => [
					CallType::ALL->value => CallType::getTitle(CallType::ALL->value),
					CallType::INCOMING->value => CallType::getTitle(CallType::INCOMING->value),
					CallType::OUTGOING->value => CallType::getTitle(CallType::OUTGOING->value),
				],
			],
			'IS_ENABLED' => [
				'id' => 'IS_ENABLED',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_ACTIVITY'),
				'type' => 'list',
				'items' => [
					'N' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_IS_ENABLED_DISABLED'),
					'Y' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_IS_ENABLED_ENABLED'),
				],
			],
			'UPDATED_AT' => [
				'id' => 'UPDATED_AT',
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_MODIFIED_BY'),
				'type' => 'date',
			],
		];
	}

	private function getOrder(): array
	{
		return $this->getGridOptions()->GetSorting([
			'sort' => ['UPDATED_AT' => 'desc'],
		])['sort'];
	}

	private function getGridOptions(): Options
	{
		if ($this->gridOptions === null)
		{
			$this->gridOptions = new Options($this->getGridId());
		}

		return $this->gridOptions;
	}

	private function getGridId(): string
	{
		return 'crm_copilot_call_assessment_grid';
	}

	private function getPageNavigation(): PageNavigation
	{
		if ($this->pageNavigation === null)
		{
			$recordCount = CopilotCallAssessmentController::getInstance()->getTotalCount($this->getFilterConditions());

			$pageNavigation = new PageNavigation($this->getPageNavigationId());
			$pageNavigation
				->allowAllRecords(false)
				->setPageSize($this->getPageSize())
				->setRecordCount($recordCount)
				->initFromUri()
			;

			$this->pageNavigation = $pageNavigation;
		}

		return $this->pageNavigation;
	}

	private function getPageNavigationId(): string
	{
		return "{$this->getGridId()}_{$this->navParamName}";
	}

	private function getPageSize(): int
	{
		$navParams = $this->getGridOptions()->getNavParams([
			'nPageSize' => static::DEFAULT_PAGE_SIZE,
		]);

		return (int)$navParams['nPageSize'];
	}

	private function getPreparedFieldsData(Main\ORM\Objectify\Collection $callAssessments): array
	{
		$userIds = [];
		$callAssessmentIds = [];

		foreach ($callAssessments as $callAssessment)
		{
			$userIds[] = $callAssessment->getCreatedById();
			$userIds[] = $callAssessment->getUpdatedById();

			$callAssessmentIds[] = $callAssessment->getId();
		}

		if (empty($userIds))
		{
			$users = [];
		}
		else
		{
			$userBroker = Container::getInstance()->getUserBroker();
			$users = $userBroker->getBunchByIds($userIds);
		}

		if (empty($callAssessmentIds))
		{
			$clientTypes = [];
			$assessments = [];
		}
		else
		{
			$clientTypeController = CopilotCallAssessmentClientTypeController::getInstance();
			$clientTypes = $clientTypeController->getByAssessmentIds($callAssessmentIds);

			// @todo calc assessments only if select assessment field
			$assessments = (new RatingCalculator())->calculateRatingByAssessmentIds($callAssessmentIds);
		}

		$callCounts = [];
		if ($this->isV2 && !empty($callAssessmentIds) && $this->isMatchColumnVisible())
		{
			$callCounts = AiQualityAssessmentController::getInstance()
				->countByAssessmentSettingIds($callAssessmentIds)
			;
		}

		return [
			'users' => $users,
			'clientTypes' => $clientTypes,
			'assessments' => $assessments,
			'callCounts' => $callCounts,
		];
	}

	private function isMatchColumnVisible(): bool
	{
		return in_array('MATCH', $this->getGridOptions()->getUsedColumns(['MATCH']), true);
	}

	private function getField(
		string $fieldName,
		CopilotCallAssessment $callAssessmentItem,
		array $fieldsData = [],
	): string
	{
		$content = '';

		if ($fieldName === 'CLIENT')
		{
			$content = $this->getClientField($callAssessmentItem, $fieldsData);
		}
		elseif ($fieldName === 'CALL_TYPE')
		{
			$content = $this->getCallTypeField($callAssessmentItem);
		}
		elseif ($fieldName === 'PROMPT')
		{
			$content = $this->getPromptField($callAssessmentItem);
		}
		elseif ($fieldName === 'IS_ENABLED')
		{
			$content = $this->getIsEnabledField($callAssessmentItem);
		}
		elseif ($fieldName === 'ASSESSMENT_AVG')
		{
			$content = $this->getAssessmentAvgField($callAssessmentItem, $fieldsData);
		}
		elseif ($fieldName === 'TITLE')
		{
			$content = $this->getTitleField($callAssessmentItem);
		}
		elseif ($fieldName === 'ID')
		{
			$content = $this->getIdField($callAssessmentItem);
		}
		elseif ($fieldName === 'MODIFIED')
		{
			$content = $this->getModifiedField($callAssessmentItem, $fieldsData);
		}
		elseif ($fieldName === 'GIST')
		{
			$content = $this->getGistField($callAssessmentItem, $fieldsData);
		}

		return '<div class="crm-copilot-call-assessment-list--field-wrapper">' . $content . '</div>';
	}

	private function getClientField(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		if (empty($fieldsData['clientTypes']))
		{
			return '';
		}

		$results = [];

		foreach ($fieldsData['clientTypes'] as $clientTypeData)
		{
			if ((int)$clientTypeData['ASSESSMENT_ID'] === $callAssessmentItem->getId())
			{
				$results[] = ClientType::getTitle($clientTypeData['CLIENT_TYPE_ID']);
			}
		}

		return implode(', ', $results);
	}

	private function getCallTypeField(CopilotCallAssessment $callAssessmentItem): string
	{
		return CallType::getTitle($callAssessmentItem->getCallType());
	}

	private function getPromptField(CopilotCallAssessment $callAssessmentItem): string
	{
		$id = $callAssessmentItem->getId();
		$textCode = (
			$this->isReadOnly()
				? 'CRM_COMMON_ACTION_SHOW'
				: 'CRM_COMMON_ACTION_EDIT'
		);

		$buttonParams = [
			'id' => 'crm-copilot-call-assessment-list-edit-' . $id,
			'dataset' => [
				'btn-uniqid' => 'crm-copilot-call-assessment-list-edit-' . $id,
			],
			'color' => UI\Buttons\Color::LIGHT_BORDER,
			'text' => Loc::getMessage($textCode),
			'size' => UI\Buttons\Size::EXTRA_SMALL,
			'link' => $this->getDetailsUri($id)->getUri(),
		];

		if ($this->isV2)
		{
			$buttonParams['style'] = UI\Buttons\AirButtonStyle::OUTLINE_NO_ACCENT;
		}
		else
		{
			$buttonParams['round'] = true;
		}

		$button = new UI\Buttons\Button($buttonParams);
		if ($this->isV2)
		{
			$button->addClass('crm-copilot-call-assessment-list--v2-edit-btn');
		}

		return $button->render();
	}

	private function getIsEnabledField(CopilotCallAssessment $callAssessmentItem): string
	{
		$id = $callAssessmentItem->getId();
		$switcherId = 'crm-copilot-call-assessment-list-is-enabled-' . $id;
		$switcherIdEscaped = CUtil::JSEscape($switcherId);

		$params = Main\Web\Json::encode([
			'id' => $id,
			'targetNodeId' => $switcherIdEscaped,
			'checked' => $callAssessmentItem->getIsEnabled(),
			'readOnly' => $this->isReadOnly(),
		]);

		$className = 'crm-copilot-call-assessment-list-modified-field';
		$iconContainer = '';
		if (
			Feature::enabled(Feature\CopilotCallAssessmentAvailability::class)
			&& AvailabilityType::isExtendedAvailabilityType($callAssessmentItem->getAvailabilityType())
			&& !in_array($id, $this->currentAvailableAssessmentIds, true)
		)
		{
			$className .= ' --not-available';
			$iconContainer = $this->getAvailabilityIconContainer();
		}

		return <<<HTML
			<div class="{$className}">
				<div class="crm-copilot-call-assessment-list-modified-field-switcher-wrapper">
					<div id="{$switcherId}"></div>
					{$iconContainer}
				</div>
				{$this->getAvailabilityDataContent($callAssessmentItem)}
			</div>
			<script>
				BX.ready(() => {
					const isEnabledField = new BX.Crm.Copilot.CallAssessmentList.ActiveField({$params});

					isEnabledField.init();
					
					BX.UI.Hint.init(BX('crm-copilot-call-assessment-list-modified-field-switcher-wrapper'));
				});
			</script>
HTML;
	}

	private function getAvailabilityDataContent(CopilotCallAssessment $callAssessmentItem): string
	{
		if (!Feature::enabled(Feature\CopilotCallAssessmentAvailability::class))
		{
			return '';
		}

		$item = CallAssessmentItem::createFromEntity($callAssessmentItem);
		$availabilityData = $item->getAvailabilityData();
		if (empty($availabilityData))
		{
			return '';
		}

		$availabilityType = $item->getAvailabilityType();
		if ($availabilityType === AvailabilityType::PERIOD->value)
		{
			$format = $this->getCultureDateTimeFormat();
			$result = array_map(
				static fn($row) => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_AVAILABILITY_PERIOD', [
					'#FROM#' => FormatDate($format, CCrmDateTimeHelper::getUserTime($row['startPoint'])->getTimestamp()),
					'#TO#' => FormatDate($format, CCrmDateTimeHelper::getUserTime($row['endPoint'])->getTimestamp()),
				]),
				$availabilityData,
			);

			return '
				<div class="crm-copilot-call-assessment-list-modified-field-availability">'
				. implode('<br>', $result)
				. '</div>';
		}

		if ($availabilityType === AvailabilityType::CUSTOM->value)
		{
			$result = array_map(
				static fn($row) => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_AVAILABILITY_CUSTOM', [
					'#WEEKDAY_TYPE#' => AvailabilityWeekdayType::getTitle($row['weekdayType']),
					'#FROM#' => CCrmDateTimeHelper::getUserTime($row['startPoint'])->format('H:i'),
					'#TO#' => CCrmDateTimeHelper::getUserTime($row['endPoint'])->format('H:i'),
				]),
				$availabilityData,
			);

			return '
				<div class="crm-copilot-call-assessment-list-modified-field-availability">'
				. implode('<br>', $result)
				. '</div>';
		}

		return '';
	}

	private function getAvailabilityIconContainer(): string
	{
		return '
			<div class="crm-copilot-call-assessment-list-modified-field-availability-icon-container">
				<span
					class="crm-copilot-call-assessment-list-modified-field-availability-icon"
					data-hint="' . Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_AVAILABILITY_HINT') . '"
					data-hint-html
					data-hint-no-icon
				></span>
			</div>
		';
	}

	private function getAssessmentAvgField(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		$value = $fieldsData['assessments'][$callAssessmentItem->getId()] ?? 0;

		$id = $callAssessmentItem->getId();
		$fieldId = 'crm-copilot-call-assessment-list-assessment-avg-' . $id;
		$fieldIdEscaped = CUtil::JSEscape($fieldId);

		$borders = [
			[
				'value' => $callAssessmentItem->getLowBorder(),
				'color' => '#FF5752',
				'id' => 'lowBorder',
			],
			[
				'color' => '#2FC6F6',
				'id' => 'default',
			],
			[
				'value' => $callAssessmentItem->getHighBorder(),
				'color' => '#9DCF00',
				'id' => 'highBorder',
			],
		];

		$params = Main\Web\Json::encode([
			'id' => $id,
			'targetNodeId' => $fieldIdEscaped,
			'value' => $value > 0 ? $value : null,
			'borders' => $borders,
		]);

		return <<<HTML
			<div id="{$fieldId}"></div>
			<script>
				BX.ready(() => {
					const roundChartField = new BX.Crm.Copilot.CallAssessmentList.RoundChartField({$params});

					roundChartField.init();
				});
			</script>
HTML;
	}

	private function getTitleField(CopilotCallAssessment $callAssessmentItem): string
	{
		$id = $callAssessmentItem->getId();
		$uri = $this->getDetailsUri($id);
		$badgeHtml = null;

		if ($callAssessmentItem->getStatus() === QueueTable::EXECUTION_STATUS_ERROR)
		{
			$badge = Badge::createByType(
				Badge::COPILOT_CALL_ASSESSMENT_STATUS_TYPE,
				CopilotCallAssessmentStatus::ERROR_VALUE,
			);
			$badgeHtml = BadgeBuilder::render([$badge->getConfigFromMap()]);
		}

		$descriptionHtml = '';
		if ($this->isV2)
		{
			$description = trim((string)$callAssessmentItem->getDescription());
			if ($description !== '')
			{
				$descriptionHtml = '<div class="crm-copilot-call-assessment-list--title-description">'
					. nl2br(htmlspecialcharsbx($description))
					. '</div>';
			}
		}

		$wrapperClass = 'crm-copilot-call-assessment-list--field-column';
		if ($this->isV2)
		{
			$wrapperClass .= ' crm-copilot-call-assessment-list--v2-title';
		}

		return '<div class="' . $wrapperClass . '"><a href="' . $uri . '">'
			. htmlspecialcharsbx($callAssessmentItem->getTitle())
			. '</a>' . $badgeHtml . $descriptionHtml . '</div>';
	}

	private function getIdField(CopilotCallAssessment $callAssessmentItem): string
	{
		$id = $callAssessmentItem->getId();
		$uri = $this->getDetailsUri($id);

		return '<a href="' . $uri . '">' . $callAssessmentItem->getId() . '</a>';
	}

	private function getDetailsUri(int $id): Uri
	{
		return new Main\Web\Uri('/crm/copilot-call-assessment/details/' . $id . '/');
	}

	private function getModifiedField(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		$userInfo = $fieldsData['users'][$callAssessmentItem->getUpdatedById()] ?? [];
		$name = htmlspecialcharsbx($userInfo['FORMATTED_NAME'] ?? '');
		$updatedAt = $this->formatDateTime($callAssessmentItem->getUpdatedAt());

		$classPrefix = 'crm-copilot-call-assessment-list-modified-field';
		$wrapperClass = $classPrefix;
		if ($this->isV2)
		{
			$wrapperClass .= ' crm-copilot-call-assessment-list--v2-modified';
		}
		$date = '<div class="' . $classPrefix . '-date">' . $updatedAt . '</div>';
		$user = '<div class="' . $classPrefix . '-user">' . $name . '</div>';

		return '<div class="' . $wrapperClass . '">' . $date . $user . '</div>';
	}

	private function getGistField(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		return '<div>' . nl2br(htmlspecialcharsbx($callAssessmentItem->getGist())) . '</div>';
	}

	private function getMatchBadge(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		$value = (int)($fieldsData['assessments'][$callAssessmentItem->getId()] ?? 0);
		if (($fieldsData['callCounts'][$callAssessmentItem->getId()] ?? 0) <= 0)
		{
			return '';
		}

		$zone = 'default';
		$lowBorder = (int)$callAssessmentItem->getLowBorder();
		$highBorder = (int)$callAssessmentItem->getHighBorder();
		if ($lowBorder > 0 && $value < $lowBorder)
		{
			$zone = 'low';
		}
		elseif ($highBorder > 0 && $value >= $highBorder)
		{
			$zone = 'high';
		}

		return sprintf(
			'<span class="crm-copilot-call-assessment-list--match-badge --%s">%d%%</span>',
			$zone,
			$value,
		);
	}

	private function getMatchField(CopilotCallAssessment $callAssessmentItem, array $fieldsData): string
	{
		$badge = $this->getMatchBadge($callAssessmentItem, $fieldsData);
		$callAssessmentItemId = $callAssessmentItem->getId();
		$count = $fieldsData['callCounts'][$callAssessmentItemId] ?? 0;
		if ($count <= 0)
		{
			return $badge;
		}

		$url = $this->getCallsListUri($callAssessmentItemId);
		$text = Loc::getMessagePlural(
			'CRM_COPILOT_CALL_ASSESSMENT_LIST_MATCH_CALLS_LINK',
			$count,
			['#COUNT#' => $count],
		);

		return $badge . sprintf(
				'<a class="crm-copilot-call-assessment-list--match-link" href="%s" data-call-assessment-id="%d">%s</a>',
				htmlspecialcharsbx($url->getUri()),
				$callAssessmentItemId,
				htmlspecialcharsbx($text),
			);
	}

	private function getCallsListUri(int $assessmentId): Main\Web\Uri
	{
		return new Main\Web\Uri('/crm/copilot-call-assessment/' . $assessmentId . '/calls/');
	}

	private function formatDateTime(DateTime $dateTime): string
	{
		$dateTime = $dateTime->toUserTime();

		$format = $this->getDefaultDateTimeFormat();
		if ($format === null)
		{
			return $dateTime->toString();
		}

		$userNow = CCrmDateTimeHelper::getUserTime(new DateTime());

		$offset = ($userNow->getTimestamp() - $dateTime->getTimestamp());
		$isLessThanOneMinute = $offset / 60 < 1;
		if ($isLessThanOneMinute)
		{
			return Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_FORMAT_DATE_NOW');
		}

		return FormatDate($format, $dateTime, $userNow);
	}

	private function getDefaultDateTimeFormat(): string|array|null
	{
		if ($this->defaultDateTimeFormat !== null)
		{
			return $this->defaultDateTimeFormat;
		}

		$format = $this->getCultureDateTimeFormat();
		$layoutSettings = LayoutSettings::getCurrent();
		if ($layoutSettings && $layoutSettings->isSimpleTimeFormatEnabled())
		{
			$timeFormat = Application::getInstance()->getContext()->getCulture()?->getShortTimeFormat();

			$this->defaultDateTimeFormat = [
				'tomorrow' => 'tomorrow, ' . $timeFormat,
				'i' => 'iago',
				'today' => 'today, ' . $timeFormat,
				'yesterday' => 'yesterday, ' . $timeFormat,
				'-' => $format,
			];
		}
		else
		{
			$this->defaultDateTimeFormat = preg_replace(
				'/:s$/',
				'',
				$format,
			);
		}

		return $this->defaultDateTimeFormat;
	}

	private function getColumns(): array
	{
		$columns = [];

		$columns[] = [
			'id' => 'ID',
			'default' => false,
			'name' => 'ID',
			'sort' => 'ID',
		];
		$columns[] = [
			'id' => 'TITLE',
			'default' => true,
			'name' => Loc::getMessage('CRM_COMMON_TITLE'),
			'sort' => 'TITLE',
		];
		$columns[] = [
			'id' => 'CLIENT',
			'default' => false,
			'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_CLIENT'),
		];
		$columns[] = [
			'id' => 'CALL_TYPE',
			'default' => false,
			'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_CALL_TYPE'),
		];
		$columns[] = [
			'id' => 'IS_ENABLED',
			'default' => true,
			'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_ACTIVITY'),
			'sort' => 'IS_ENABLED',
		];
		if ($this->isV2)
		{
			$columns[] = [
				'id' => 'MATCH',
				'default' => true,
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_ASSESSMENT_AVG'),
			];
		}
		else
		{
			$columns[] = [
				'id' => 'ASSESSMENT_AVG',
				'default' => false,
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_ASSESSMENT_AVG'),
			];
		}
		$columns[] = [
			'id' => 'PROMPT',
			'default' => true,
			'name' => Loc::getMessage(
				$this->isV2
					? 'CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_PROMPT_V2'
					: 'CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_PROMPT',
			),
		];
		if (!$this->isV2 && $this->needShowGistColumn())
		{
			$columns[] = [
				'id' => 'GIST',
				'default' => true,
				'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_GIST'),
			];
		}
		$columns[] = [
			'id' => 'MODIFIED',
			'default' => true,
			'name' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_COLUMN_MODIFIED_BY'),
			'sort' => 'UPDATED_AT',
		];

		return $columns;
	}

	protected function getToolbarParameters(): array
	{
		$parameters = [
			'isWithFavoriteStar' => true,
			'hideBorder' => true,
		];

		if ($this->isReadOnly())
		{
			return array_merge(parent::getToolbarParameters(), $parameters);
		}

		$buttons = [];

		$isCopilotEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment);
		$sliderWidth = AIManager::isCallScoringV2Enabled() ? 729 : 700;

		$isV2Enabled = $this->isV2 ? 'true' : 'false';
		$buttons[UI\Toolbar\ButtonLocation::AFTER_TITLE][] = new UI\Buttons\Button([
			'color' => UI\Buttons\Color::SUCCESS,
			'text' => Loc::getMessage('CRM_COMMON_ACTION_CREATE'),
			'onclick' => new UI\Buttons\JsCode(
				"(new BX.Crm.Copilot.CallAssessmentList.ActionButton(" . ($isCopilotEnabled ? 'true' : 'false') . ", $sliderWidth, $isV2Enabled)).execute()",
			),
		]);

		if ($this->isV2)
		{
			$biReportOnClick = ClickEventWrapper::wrap(
				BiReportButton::getInstance()->getOnClickJsCode('crm_copilot_call_assessment_list'),
				ClickEventBuilder::BiReport,
			);
			if ($biReportOnClick !== null)
			{
				$buttons[UI\Toolbar\ButtonLocation::RIGHT][] = new UI\Buttons\Button([
					'color' => UI\Buttons\Color::LIGHT_BORDER,
					'style' => UI\Buttons\AirButtonStyle::OUTLINE_NO_ACCENT,
					'size'  => UI\Buttons\Size::SMALL,
					'text'  => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_BI_REPORT'),
					'icon'  => null,
					'attributes' => [
						'title' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_BI_REPORT_HINT'),
					],
					'onclick' => $biReportOnClick,
				]);
			}

			$buttons[UI\Toolbar\ButtonLocation::RIGHT][] = $this->buildSettingsButton();
		}

		$parameters['buttons'] = $buttons;

		return array_merge(parent::getToolbarParameters(), $parameters);
	}

	private function buildSettingsButton(): UI\Buttons\Button
	{
		return new UI\Buttons\Button([
			'color' => UI\Buttons\Color::LIGHT_BORDER,
			'style' => UI\Buttons\AirButtonStyle::OUTLINE_NO_ACCENT,
			'size'  => UI\Buttons\Size::SMALL,
			'text'  => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_SETTINGS'),
			'icon'  => null,
			'dropdown' => true,
			'menu' => [
				'id' => 'crm-copilot-call-assessment-list-settings-menu',
				'closeByEsc' => true,
				'angle' => true,
				'items' => [
					[
						'text' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_SETTINGS_ASSESSMENT'),
						'onclick' => ClickEventWrapper::wrap(
							$this->buildOpenAgentSetupOnClick(),
							ClickEventBuilder::ScriptWizard,
						),
					],
					[
						'html' => Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_LIST_SETTINGS_REPORTING'),
						'onclick' => ClickEventWrapper::wrap(
							$this->buildOpenSummaryMasterOnClick(),
							ClickEventBuilder::SummaryWizard,
						),
					],
				],
			],
		]);
	}

	private function isReadOnly(): bool
	{
		return !Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit();
	}

	private function buildOpenAgentSetupOnClick(): UI\Buttons\JsCode
	{
		$templateId = (new CallAssessmentAiAgent())->findLaunchedTemplateId();
		if ($templateId === null)
		{
			return new UI\Buttons\JsCode('');
		}

		$js = <<<JS
			BX.Runtime.loadExtension('bizproc.setup-template').then(({ SetupTemplate }) => {
				BX.ajax.runAction('bizproc.v2.Integration.AiAgent.Template.start', {
					json: {
						templateId: {$templateId},
					},
				}).then((result) => {
					const data = result?.data?.setupTemplateData;
					if (data && SetupTemplate)
					{
						SetupTemplate.showSidePanel(data);
					}
				});
			});
JS;

		return new UI\Buttons\JsCode($js);
	}

	private function buildOpenSummaryMasterOnClick(): UI\Buttons\JsCode
	{
		$path = '/crm/copilot-call-assessment/summary/';

		return new UI\Buttons\JsCode(
			"BX.SidePanel.Instance.open('$path', { cacheable: false, width: 700 });",
		);
	}

	private function needShowGistColumn(): bool
	{
		return !is_null($this->request->get('criteria'));
	}

	private function getCultureDateTimeFormat(): ?string
	{
		$culture = Application::getInstance()->getContext()->getCulture();
		if ($culture === null)
		{
			return null;
		}

		$shortTimeFormat = $culture->getShortTimeFormat();

		return $culture->getLongDateFormat() . ', ' . $shortTimeFormat;
	}

	protected function getTopPanelParameters(): array
	{
		return array_merge(
			parent::getTopPanelParameters(),
			['ACTIVE_ITEM_ID' => 'CALL_ASSESSMENT'],
		);
	}
}
