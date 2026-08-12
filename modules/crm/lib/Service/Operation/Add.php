<?php

namespace Bitrix\Crm\Service\Operation;

use Bitrix\Crm\Activity\Entity;
use Bitrix\Crm\Activity\Provider\ToDo;
use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Builder\AnalyticsEventDto;
use Bitrix\Crm\Integration\Analytics\Builder\Entity\AddEvent;
use Bitrix\Crm\Integration\Analytics\Builder\Entity\ConvertEvent;
use Bitrix\Crm\Integration\Analytics\Builder\Entity\CopyEvent;
use Bitrix\Crm\Integration\Analytics\Builder\Robot\CreateEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\BizProc\Starter\CrmStarter;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\Integrity;
use Bitrix\Crm\Item;
use Bitrix\Crm\Kanban\EntityActivityDeadline;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Operation;
use Bitrix\Crm\Statistics;
use Bitrix\Crm\Timeline\MarkController;
use Bitrix\Crm\Timeline\TimelineManager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class Add extends Operation
{
	public function __construct(Item $item, Operation\Settings $settings, Collection $fieldsCollection = null)
	{
		parent::__construct($item, $settings, $fieldsCollection);
		$this->bizProcEventType = \CCrmBizProcEventType::Create;
	}

	public function checkAccess(): Result
	{
		$result = new Result();

		$userPermissions = Container::getInstance()->getUserPermissions($this->getContext()->getUserId());
		$canAddItem = $userPermissions->item()->canAddItem($this->item);

		if(!$canAddItem)
		{
			$result->addError(
				new Error(
					Loc::getMessage('CRM_TYPE_ITEM_PERMISSIONS_ADD_DENIED'),
					static::ERROR_CODE_ITEM_ADD_ACCESS_DENIED
				)
			);
		}

		return $result;
	}

	protected function save(): Result
	{
		return $this->item->save(
			$this->isCheckFieldsEnabled()
			&& $this->isCheckRequiredUserFields()
			&& $this->isCheckRequiredByAttributeUserFields()
		);
	}

	protected function registerDuplicateCriteria(): void
	{
		$registrar = Integrity\DuplicateManager::getCriterionRegistrar($this->getItem()->getEntityTypeId());

		$registrar->registerByItem($this->getItem());
	}

	protected function notifyCounterMonitor(): void
	{
		$fieldsValues = [];
		foreach ($this->getCounterMonitorSignificantFields() as $commonFieldName => $entityFieldName)
		{
			$fieldsValues[$entityFieldName] = $this->item->get($commonFieldName);
		}
		\Bitrix\Crm\Counter\Monitor::getInstance()->onEntityAdd($this->getItem()->getEntityTypeId(), $fieldsValues);
	}

	protected function registerStatistics(Statistics\OperationFacade $statisticsFacade): Result
	{
		return $statisticsFacade->add($this->item);
	}

	protected function saveToHistory(): Result
	{
		$registrar = Container::getInstance()->getRelationRegistrar();

		$registrar->registerByFieldsChange(
			$this->getItemIdentifier(),
			$this->fieldsCollection->toArray(),
			[],
			$this->item->getData(),
			$this->getItemsThatExcludedFromTimelineRelationEventsRegistration(),
			$this->getContext(),
		);

		if ($this->item->hasField(Item::FIELD_NAME_CONTACT_BINDINGS))
		{
			$registrar->registerByBindingsChange(
				$this->getItemIdentifier(),
				\CCrmOwnerType::Contact,
				[],
				$this->item->getContactBindings(),
				$this->getItemsThatExcludedFromTimelineRelationEventsRegistration(),
				$this->getContext(),
			);
		}

		if ($this->item->hasField(Item\Contact::FIELD_NAME_COMPANY_BINDINGS))
		{
			$registrar->registerByBindingsChange(
				$this->getItemIdentifier(),
				\CCrmOwnerType::Company,
				[],
				$this->item->get(Item\Contact::FIELD_NAME_COMPANY_BINDINGS),
				$this->getItemsThatExcludedFromTimelineRelationEventsRegistration(),
				$this->getContext(),
			);
		}

		return new Result();
	}

	protected function createTimelineRecord(): void
	{
		$timelineController = TimelineManager::resolveController([
			'ASSOCIATED_ENTITY_TYPE_ID' => $this->item->getEntityTypeId()
		]);

		if ($timelineController)
		{
			$timelineController->onCreate(
				$this->item->getId(),
				[
					'FIELDS' => $this->item->getData(),
					'FIELDS_MAP' => $this->item->getFieldsMap(),
				]
			);
		}

		$factory = Container::getInstance()->getFactory($this->getItem()->getEntityTypeId());
		if (!$factory?->isStagesEnabled())
		{
			return;
		}

		$stageId = (string)$this->item->getStageId();
		$newStage = $factory?->getStage($stageId);
		if (!$newStage)
		{
			return;
		}

		$wasItemMovedToFinalStage = (
			$factory?->isStagesEnabled()
			&& PhaseSemantics::isFinal($newStage->getSemantics())
		);

		if ($wasItemMovedToFinalStage)
		{
			MarkController::getInstance()->onItemMoveToFinalStage(
				$this->getItemIdentifier(),
				$stageId,
				$this->getContext()->getUserId(),
			);
		}
	}

	protected function createToDoActivity(): void
	{
		parent::createToDoActivity();

		$context = $this->getContext();
		$viewMode = $context->getItemOption('VIEW_MODE');

		if ($viewMode === \Bitrix\Crm\Kanban\ViewMode::MODE_ACTIVITIES)
		{
			$stageId = $context->getItemOption(\Bitrix\Crm\Kanban\Entity\EntityActivities::ACTIVITY_STAGE_ID);
			if (!$stageId)
			{
				$factory = $this->getFactory();
				$stageId = $factory
					? $context->getItemOption($factory->getEntityFieldNameByMap(Item::FIELD_NAME_STAGE_ID))
					: null;
			}
			if (!$stageId)
			{
				return;
			}

			$deadline = (new EntityActivityDeadline())->getDeadline($stageId);

			if ($deadline)
			{
				(new Entity\ToDo($this->getItemIdentifier(), new ToDo\ToDo()))
					->createWithDefaultSubjectAndDescription($deadline);
			}
		}
	}

	protected function getFactory(): ?Factory
	{
		return Container::getInstance()->getFactory($this->getItem()->getEntityTypeId());
	}

	protected function sendPullEvent(): void
	{
		parent::sendPullEvent();

		PullManager::getInstance()->sendItemAddedEvent($this->pullItem, $this->pullParams);
	}

	protected function runAutomation(): Result
	{
		$result = parent::runAutomation();

		if($result->isSuccess() && isset($result->getData()['runData'], $result->getData()['newStarter']))
		{
			/** @var CrmStarter $starter */
			$starter = $result->getData()['newStarter'];
			/** @var RunDataDto $runData */
			$runData = $result->getData()['runData'];

			return $starter->runAutomation($runData, \CCrmBizProcEventType::Create);
		}

		return $result;
	}

	protected function checkLimits(): Result
	{
		$result = parent::checkLimits();

		$addOperationRestriction = RestrictionManager::getAddOperationRestriction($this->item->getEntityTypeId());
		if (!$addOperationRestriction->hasPermission())
		{
			$result->addError(
				new Error(
					$addOperationRestriction->getErrorMessage(),
					$addOperationRestriction->getErrorCode(),
				)
			);
		}

		return $result;
	}

	protected function isClearItemCategoryCacheNeeded(): bool
	{
		return true;
	}

	protected function isClearItemStageCacheNeeded(): bool
	{
		return true;
	}

	protected function sendAnalytics(Result $result): void
	{
		$analytics = $this->getContext()->getAnalytics();
		if ($analytics->isEmpty())
		{
			AddEvent::createDefault($this->getItem()->getEntityTypeId())
				->setSection(Dictionary::SECTION_UNKNOWN)
				->setStatus($result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR)
				->buildEvent()
				->send()
			;

			return;
		}

		$event = $this->getAnalyticsEvent($analytics, $this->getItem());

		$analytics->fillEventBuilder($event);

		$status = $result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR;
		$event
			->setStatus($status)
			->buildEvent()
			->send()
		;
	}

	protected function getAnalyticsEvent(AnalyticsEventDto $analytics, Item $item): AbstractBuilder
	{
		$event =
			$analytics->getCategory() === Dictionary::CATEGORY_ROBOT_OPERATIONS
				? new CreateEvent($item->getEntityTypeId(), $analytics->getType())
				: null
		;

		$event ??= match ($analytics->getEvent())
		{
			Dictionary::EVENT_ENTITY_COPY => CopyEvent::createDefault($item->getEntityTypeId()),
			Dictionary::EVENT_ENTITY_CONVERT => ConvertEvent::createDefault($item->getEntityTypeId()),
			default => AddEvent::createDefault($item->getEntityTypeId()),
		};

		return $event;
	}
}
