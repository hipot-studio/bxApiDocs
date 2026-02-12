<?php

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
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
		$crmDynamicTypesMap =
			\Bitrix\Crm\Service\Container::getInstance()
				->getDynamicTypesMap()
				->load(['isLoadStages' => false, 'isLoadCategories' => false])
		;
		foreach ($crmDynamicTypesMap->getTypes() as $dynamicType)
		{
			$dynamic[] = CCrmBizProcHelper::resolveDocumentType($dynamicType->getEntityTypeId());
		}

		if ($parameters->isOnlyDynamic())
		{
			$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => $dynamic]));

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

			$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => array_merge($basic, $dynamic)]));

			return;
		}

		$event->addResult(new EventResult(EventResult::SUCCESS, ['documentTypes' => array_merge($basic, $dynamic)]));
	}
}
