<?php

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentFieldTypesEvent\OnGetDocumentFieldTypesEvent;
use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Crm;
use Bitrix\Crm\Integration\BizProc\Events\OnGetDocumentType\CrmDocumentTypeFilter;
use Bitrix\Main\Event;
use Bitrix\Crm\Activity\Provider\Bizproc;
use Bitrix\Main\EventResult;
use CCrmBizProcHelper;
use CCrmOwnerType;
use CCrmSaleHelper;

class EventHandler
{
	/**
	 * Event handler for onAfterWorkflowKill event.
	 * Deletes activities that were created by timeleine.
	 *
	 * @param Event $event Event data.
	 *
	 * @return void
	 */
	public static function onAfterWorkflowKill(Event $event): void
	{
		$workflowId = $event->getParameter('ID');

		$activities = \Bitrix\Crm\ActivityTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ORIGIN_ID' => $workflowId,
				'=COMPLETED' => 'N',
				'@PROVIDER_ID' => [Bizproc\Comment::getId(), Bizproc\Task::getId(), Bizproc\Workflow::getId()],
			],
		])->fetchAll();

		foreach ($activities as $activity)
		{
			\CCrmActivity::Delete($activity['ID']);
		}
	}

	public static function onWorkflowCommentAdded(Event $event): void
	{
		static::handleCommentEvent($event, Crm\Timeline\Bizproc\Data\CommentStatus::Created);
	}

	public static function onWorkflowCommentDeleted(Event $event): void
	{
		static::handleCommentEvent($event, Crm\Timeline\Bizproc\Data\CommentStatus::Deleted);
	}

	public static function onWorkflowAllCommentViewed(Event $event): void
	{
		static::handleCommentEvent($event, Crm\Timeline\Bizproc\Data\CommentStatus::Viewed);
	}

	private static function handleCommentEvent(Event $event, Crm\Timeline\Bizproc\Data\CommentStatus $status): void
	{
		$workflowId = $event->getParameter('workflowId');
		$userId = $event->getParameter('userId');

		$documentId = \CBPStateService::getStateDocumentId($workflowId);
		if (!$documentId)
		{
			return;
		}

		$documentId = \CBPHelper::parseDocumentId($documentId);

		Crm\Timeline\Bizproc\Controller::getInstance()->onCommentStatusChange(
			new Crm\Timeline\Bizproc\Dto\CommentStatusChangedDto(
				$workflowId,
				$documentId,
				$userId,
				$status,
			)
		);
	}

	/**
	 * Event handler for onGetDocumentFieldTypes event.
	 * Registers CRM-specific field types (sms_sender, mail_sender, deal_category, etc.)
	 * so they are available in the node workflow designer.
	 *
	 * @param OnGetDocumentFieldTypesEvent $event
	 *
	 * @throws \CBPArgumentNullException
	 */
	public static function onGetDocumentFieldTypes(OnGetDocumentFieldTypesEvent $event): void
	{
		$types = \CCrmDocument::GetDocumentFieldTypes('CONTACT');

		$baseTypes = \CBPHelper::GetDocumentFieldTypes();
		$crmContactTypes = array_diff_key($types, $baseTypes);

		if (!empty($crmContactTypes))
		{
			$event->addFieldTypes($crmContactTypes);
		}
	}

	public static function onGetDocumentType(OnGetDocumentTypeEvent $event): void
	{
		$parameters = new CrmDocumentTypeFilter();
		$event->loadModuleParameters('crm', $parameters);

		$basic = [
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Contact),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Company),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Lead),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Deal),
			// CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Invoice), // -> smart invoice
			// CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::OrderShipment), // -> no full support
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Quote),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::SmartInvoice),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::SmartDocument),
			CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::SmartB2eDocument)
		];

		if (CCrmSaleHelper::isWithOrdersMode())
		{
			$basic[] = CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::Order);
		}

		if ($parameters->isOnlyBasic())
		{
			$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => $basic]));

			return;
		}

		$dynamic = [];
		$automatedSolution = [];
		$crmDynamicTypesMap =
			\Bitrix\Crm\Service\Container::getInstance()
				->getDynamicTypesMap()
				->load(['isLoadStages' => false, 'isLoadCategories' => false])
		;

		foreach ($crmDynamicTypesMap->getTypes() as $dynamicType)
		{
			if ($dynamicType->getCustomSectionId() !== null)
			{
				$automatedSolution[] = CCrmBizProcHelper::resolveDocumentType($dynamicType->getEntityTypeId());
			}
			else
			{
				$dynamic[] = CCrmBizProcHelper::resolveDocumentType($dynamicType->getEntityTypeId());
			}
		}

		if ($parameters->isOnlyDynamic())
		{
			$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => $dynamic]));

			return;
		}

		if ($parameters->isOnlyAutomatedSolution())
		{
			$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => $automatedSolution]));

			return;
		}

		if ($parameters->isOnlyCertainEntities())
		{
			$certainEntities = $parameters->getCertainEntities();

			$basic = array_filter($basic, static function($item) use ($certainEntities) {
				return isset($item[2]) && in_array($item[2], $certainEntities, true);
			});

			$dynamic = array_filter($dynamic, static function($item) use ($certainEntities) {
				return isset($item[2]) && in_array($item[2], $certainEntities, true);
			});

			$automatedSolution = array_filter($automatedSolution, static function($item) use ($certainEntities) {
				return isset($item[2]) && in_array($item[2], $certainEntities, true);
			});

			$event->addResult(
				new EventResult(
					EventResult::SUCCESS, ['documentTypes' => array_merge($basic, $dynamic, $automatedSolution)]
				)
			);

			return;
		}

		$event->addResult(
			new EventResult(
				EventResult::SUCCESS, ['documentTypes' => array_merge($basic, $dynamic, $automatedSolution)]
			)
		);
	}
}
