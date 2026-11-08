<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Catalog;

use Bitrix\Bizproc\Activity\ActivityDescription;

final class NodeCatalogLayout
{
	private const ORDER = [
		'starter' => [
			['ScheduledTrigger'],
			['CrmEntityCreateTrigger', 'LEAD'],
			['CrmEntityCreateTrigger', 'DEAL'],
			['CrmEntityCreateTrigger', 'CONTACT'],
			['CrmEntityCreateTrigger', 'COMPANY'],
			['CrmEntityCreateTrigger', 'SMART_INVOICE'],
			['CrmEntityCreateTrigger', 'ORDER'],
			['CrmEntityCreateTrigger', 'QUOTE'],
			['CrmEntityCreateTrigger', 'DYNAMIC'],
			['CrmEntityCreateTrigger', 'AUTOMATED_SOLUTION'],
			['CrmLeadManualStartTrigger'],
			['CrmDealManualStartTrigger'],
			['CrmContactManualStartTrigger'],
			['CrmCompanyManualStartTrigger'],
			['CrmSmartInvoiceManualStartTrigger'],
			['CrmQuoteManualStartTrigger'],
			['AiAgentStartTrigger'],
			['CrmSmartManualStartTrigger', 'smart'],
			['CrmSmartManualStartTrigger', 'automatedSolution'],
			['CrmEntityFieldChangedTrigger', 'LEAD'],
			['CrmEntityFieldChangedTrigger', 'DEAL'],
			['CrmEntityFieldChangedTrigger', 'CONTACT'],
			['CrmEntityFieldChangedTrigger', 'COMPANY'],
			['CrmEntityFieldChangedTrigger', 'SMART_INVOICE'],
			['CrmEntityFieldChangedTrigger', 'QUOTE'],
			['CrmEntityFieldChangedTrigger', 'DYNAMIC'],
			['CrmEntityFieldChangedTrigger', 'AUTOMATED_SOLUTION'],
			['CrmEntityFieldChangedTrigger', 'ORDER'],
			['CrmAutomationTrigger', 'automation_RESP_CHANGED'],
			['CrmAutomationTrigger', 'automation_EMAIL'],
			['CrmAutomationTrigger', 'automation_EMAIL_SENT'],
			['CrmAutomationTrigger', 'automation_EMAIL_READ'],
			['CrmAutomationTrigger', 'automation_EMAIL_LINK'],
			['CrmAutomationTrigger', 'automation_CALL'],
			['CrmAutomationTrigger', 'automation_OUTGOING_CALL'],
			['CrmAutomationTrigger', 'automation_MISSED_CALL'],
			['CrmAutomationTrigger', 'automation_VISIT'],
			['CrmCallAssessmentTrigger'],
			['CrmAutomationTrigger', 'automation_OPENLINE'],
			['CrmAutomationTrigger', 'automation_OPENLINE_MSG'],
			['CrmAutomationTrigger', 'automation_OPENLINE_OUTGOING_MSG'],
			['CrmAutomationTrigger', 'automation_OPENLINE_ANSWER'],
			['CrmAutomationTrigger', 'automation_OPENLINE_ANSWER_CTRL'],
			['CrmAutomationTrigger', 'automation_OPENLINE_CLOSE'],
			['CrmAutomationTrigger', 'automation_WEBFORM'],
			['CrmAutomationTrigger', 'automation_CALLBACK'],
			['CrmAutomationTrigger', 'automation_GUEST_RETURN'],
			['CrmAutomationTrigger', 'automation_INVOICE'],
			['CrmAutomationTrigger', 'automation_ORDER_PAID'],
			['CrmAutomationTrigger', 'automation_ORDER_CANCELED'],
			['CrmAutomationTrigger', 'automation_FILL_TRACKNUM'],
			['CrmAutomationTrigger', 'automation_DEDUCTED'],
			['CrmAutomationTrigger', 'automation_SHIPMENT_CHANGED'],
			['CrmAutomationTrigger', 'automation_ALLOW_DELIVERY'],
			['CrmAutomationTrigger', 'automation_DELIVERY_FINISHED'],
			['CrmAutomationTrigger', 'automation_SIGN_INITIATOR_SIGNING'],
			['CrmAutomationTrigger', 'automation_SIGN_OTHER_SIGNING'],
			['CrmAutomationTrigger', 'automation_SIGN_FINAL_SIGNING'],
			['CrmAutomationTrigger', 'automation_B2E_FILLING'],
			['CrmAutomationTrigger', 'automation_B2E_COORDINATION'],
			['CrmAutomationTrigger', 'automation_B2E_SIGNING'],
			['CrmAutomationTrigger', 'automation_B2E_COMPLETED'],
			['StartWorkTimeTrigger'],
			['StopWorkTimeTrigger'],
			['FullReportReadyTrigger'],
			['FullReportSentTrigger'],
			['AbsenceLeaveSickTrigger'],
			['AbsenceVacationTrigger'],
			['NewEmployeeTrigger'],
			['CrmAutomationTrigger', 'automation_TASK_STATUS'],
			['TasksExpiredTrigger'],
			['BookingCreatedTrigger'],
			['BookingUpdatedTrigger'],
			['BookingStatusChangedTrigger'],
			['BookingCanceledTrigger'],
		],
		'booking' => [
			['BookingCreatedTrigger'],
			['BookingUpdatedTrigger'],
			['BookingStatusChangedTrigger'],
			['BookingCanceledTrigger'],
			['BookingAiCallTrigger', 'booking_ai_call_confirmation'],
			['BookingAiCallTrigger', 'booking_ai_call_reminder'],
			['BookingAiCallTrigger', 'booking_ai_call_delayed'],
			['BookingAiCallTrigger', 'booking_ai_call_cancellation'],
			['BookingComplexActivity'],
			['BookingAddActivity'],
			['BookingUpdateActivity'],
			['BookingSetStatusActivity'],
			['BookingCancelActivity'],
			['BookingWaitListAddActivity'],
			['BookingWaitListUpdateActivity'],
			['BookingWaitListConvertActivity'],
		],
		'ai' => [
			['AiAgentStartTrigger'],
			['AiAssistantAgentComplexActivity'],
			['AiProcessingActivity'],
		],
		'sales_crm' => [
			['CrmLeadComplexActivity'],
			['CrmEntityCreateTrigger', 'LEAD'],
			['CrmLeadManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'LEAD'],
			['CrmDealComplexActivity'],
			['CrmEntityCreateTrigger', 'DEAL'],
			['CrmDealManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'DEAL'],
			['CrmContactComplexActivity'],
			['CrmEntityCreateTrigger', 'CONTACT'],
			['CrmContactManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'CONTACT'],
			['CrmCompanyComplexActivity'],
			['CrmEntityCreateTrigger', 'COMPANY'],
			['CrmCompanyManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'COMPANY'],
			['CrmSmartInvoiceComplexActivity'],
			['CrmEntityCreateTrigger', 'SMART_INVOICE'],
			['CrmSmartInvoiceManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'SMART_INVOICE'],
			['CrmEntityCreateTrigger', 'ORDER'],
			['CrmEntityFieldChangedTrigger', 'ORDER'],
			['CrmQuoteComplexActivity'],
			['CrmEntityCreateTrigger', 'QUOTE'],
			['CrmQuoteManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'QUOTE'],
			['CrmSmartProcessComplexActivity'],
			['CrmDynamicComplexActivity'],
			['CrmSmartManualStartTrigger', 'smart'],
			['CrmEntityFieldChangedTrigger', 'DYNAMIC'],
			['CrmAutomationTrigger', 'automation_RESP_CHANGED'],
			['CrmEventAddActivity'],
		],
		'lead' => [
			['CrmLeadComplexActivity'],
			['CrmEntityCreateTrigger', 'LEAD'],
			['CrmAutomationTrigger', 'automation_WEBFORM'],
			['CrmGenerateQr'],
		],
		'client_base' => [
			['CrmEntityCreateTrigger', 'CONTACT'],
			['CrmCompanyComplexActivity'],
			['CrmEntityCreateTrigger', 'COMPANY'],
			['CrmExcludeActivity'],
		],
		'digital_workplace' => [
			['CrmAutomatedSolutionComplexActivity'],
			['CrmEntityCreateTrigger', 'AUTOMATED_SOLUTION'],
			['CrmSmartManualStartTrigger', 'automatedSolution'],
			['CrmEntityFieldChangedTrigger', 'AUTOMATED_SOLUTION'],
		],
		'client_communication' => [
			['CrmSendEmailActivity'],
			['CrmAutomationTrigger', 'automation_EMAIL'],
			['CrmAutomationTrigger', 'automation_EMAIL_SENT'],
			['CrmAutomationTrigger', 'automation_EMAIL_READ'],
			['CrmAutomationTrigger', 'automation_EMAIL_LINK'],
			['CrmSendSmsActivity'],
			['VoximplantCallActivity'],
			['CrmAutomationTrigger', 'automation_CALL'],
			['CrmAutomationTrigger', 'automation_OUTGOING_CALL'],
			['CrmAutomationTrigger', 'automation_MISSED_CALL'],
			['CrmAutomationTrigger', 'automation_VISIT'],
			['CrmGetCallAssessmentActivity'],
			['ImOpenLinesMessageActivity'],
			['CrmAutomationTrigger', 'automation_OPENLINE'],
			['CrmAutomationTrigger', 'automation_OPENLINE_MSG'],
			['CrmAutomationTrigger', 'automation_OPENLINE_OUTGOING_MSG'],
			['CrmAutomationTrigger', 'automation_OPENLINE_ANSWER'],
			['CrmAutomationTrigger', 'automation_OPENLINE_ANSWER_CTRL'],
			['CrmAutomationTrigger', 'automation_OPENLINE_CLOSE'],
		],
		'payment' => [
			['CrmGetPaymentUrlActivity'],
			['CrmOrderPayActivity'],
			['CrmSmartInvoiceComplexActivity'],
			['CrmEntityCreateTrigger', 'SMART_INVOICE'],
			['CrmSmartInvoiceManualStartTrigger'],
			['CrmEntityFieldChangedTrigger', 'SMART_INVOICE'],
			['CrmAutomationTrigger', 'automation_INVOICE'],
			['CrmEntityCreateTrigger', 'ORDER'],
			['CrmEntityFieldChangedTrigger', 'ORDER'],
			['CrmAutomationTrigger', 'automation_ORDER_PAID'],
			['CrmAutomationTrigger', 'automation_ORDER_CANCELED'],
		],
		'delivery' => [
			['CrmAutomationTrigger', 'automation_FILL_TRACKNUM'],
			['CrmAutomationTrigger', 'automation_DEDUCTED'],
			['CrmAutomationTrigger', 'automation_SHIPMENT_CHANGED'],
			['CrmAutomationTrigger', 'automation_ALLOW_DELIVERY'],
			['CrmAutomationTrigger', 'automation_DELIVERY_FINISHED'],
		],
		'document_flow' => [
			['CrmAutomationTrigger', 'automation_SIGN_INITIATOR_SIGNING'],
			['CrmAutomationTrigger', 'automation_SIGN_OTHER_SIGNING'],
			['CrmAutomationTrigger', 'automation_SIGN_FINAL_SIGNING'],
		],
		'sign' => [
			['SignB2EDocumentActivity'],
			['CrmAutomationTrigger', 'automation_B2E_FILLING'],
			['CrmAutomationTrigger', 'automation_B2E_COORDINATION'],
			['CrmAutomationTrigger', 'automation_B2E_SIGNING'],
			['CrmAutomationTrigger', 'automation_B2E_COMPLETED'],
		],
		'hr' => [
			['NewEmployeeTrigger'],
			['StartWorkTimeTrigger'],
			['StopWorkTimeTrigger'],
			['SaveDayPlanActivity'],
			['FullReportReadyTrigger'],
			['HumanResourcesGetAiReportUsersActivity'],
			['FillFullReportActivity'],
			['GetFullReportActivity'],
			['FullReportSentTrigger'],
			['AbsenceLeaveSickTrigger'],
			['AbsenceVacationTrigger'],
			['CrmControlNotifyActivity'],
		],
		'team_management' => [
			['NewEmployeeTrigger'],
			['SaveDayPlanActivity'],
			['GetReportActivity'],
			['SaveReportActivity'],
			['FullReportReadyTrigger'],
			['FillFullReportActivity'],
			['GetFullReportActivity'],
			['FullReportSentTrigger'],
			['StartWorkTimeTrigger'],
			['StopWorkTimeTrigger'],
			['AbsenceLeaveSickTrigger'],
			['AbsenceVacationTrigger'],
			['CrmAutomationTrigger', 'automation_OPENLINE_ANSWER_CTRL'],
		],
		'internal_communication' => [
			['CreateWorkGroup'],
			['SocnetBlogPostActivity'],
			['ImCreateGroupChatActivity'],
			['ImAddMemberToGroupChatActivity'],
			['ImAddMessageToGroupChatActivity'],
			['ImBotCreateBotActivity'],
			['ImBotMessageActivity'],
			['ImBotNewMessageTrigger'],
			['ImMessageActivity'],
			['IMNotifyActivity'],
			['ImGetUserChatMessagesActivity'],
		],
		'task_management' => [
			['TasksComplexActivity'],
			['TasksGetInfoActivity'],
			['CrmAutomationTrigger', 'automation_TASK_STATUS'],
			['TasksExpiredTrigger'],
		],
		'task_distribution' => [
			['TasksComplexActivity'],
			['TasksGetInfoActivity'],
			['CrmAutomationTrigger', 'automation_TASK_STATUS'],
			['TasksExpiredTrigger'],
		],
		'workflow' => [
			['EmptyBlockActivity', 'FRAME'],
			['PoolNode'],
			['SetupTemplateActivity'],
			['IfElseBranchActivity'],
			['SwitchNode'],
			['MergeFlowNode'],
			['ListenActivity'],
			['DelayActivity'],
			['HandleExternalEventActivity'],
			['ForEachActivity'],
			['WhileActivity'],
			['StartWorkflowActivity'],
			['StartScriptActivity'],
			['FixResultActivity'],
			['TerminateActivity'],
			['ScheduledTrigger'],
		],
		'workflow_state' => [
			['StateNode'],
			['SetStateNode'],
			['HandleExternalEventActivity'],
			['DelayActivity'],
		],
		'storage' => [
			['CreateStorageNode'],
			['WriteDataStorageActivity'],
			['ReadDataStorageActivity'],
			['DeleteDataStorageActivity'],
		],
		'other_operations' => [
			['AbsenceActivity'],
			['Calendar2Activity'],
			['CalendarGetInform'],
			['DiskAddFolderActivity'],
			['DiskUploadActivity'],
			['DiskUploadVersionActivity'],
			['DiskDetailActivity'],
			['DiskCopyMoveActivity'],
			['DiskRemoveActivity'],
			['RandomStringActivity'],
			['CrmGenerateQr'],
		],
		'mcp' => [
			['AiAssistantInternalMcpNode', 'aiassistant'],
			['AiAssistantInternalMcpNode', 'crm'],
			['AiAssistantInternalMcpNode', 'tasks'],
			['AiAssistantInternalMcpNode', 'im'],
			['AiAssistantInternalMcpNode', 'mail'],
			['AiAssistantInternalMcpNode', 'intranet'],
			['AiAssistantInternalMcpNode', 'humanresources'],
			['AiAssistantInternalMcpNode', 'socialnetwork'],
			['AiAssistantInternalMcpNode', 'landing'],
		],
	];

	/**
	 * @param list<ActivityDescription> $items
	 * @return list<ActivityDescription>
	 */
	public function sort(string $groupId, array $items): array
	{
		if ($items === [])
		{
			return $items;
		}

		$items = self::sortByDescriptor($items);
		if (!isset(self::ORDER[$groupId]))
		{
			return $items;
		}

		$priorities = [];
		foreach (self::ORDER[$groupId] as $priority => $identity)
		{
			$priorities[$identity[0]][$identity[1] ?? ''] = $priority;
		}

		$indexedItems = [];
		foreach ($items as $index => $item)
		{
			$indexedItems[] = [
				'item' => $item,
				'index' => $index,
				'priority' => $priorities[$item->getClass()][$item->getPresetId() ?? ''] ?? PHP_INT_MAX,
			];
		}

		usort(
			$indexedItems,
			static fn(array $left, array $right) => [$left['priority'], $left['index']] <=> [$right['priority'], $right['index']],
		);

		return array_column($indexedItems, 'item');
	}

	/**
	 * @param list<ActivityDescription> $items
	 * @return list<ActivityDescription>
	 */
	private static function sortByDescriptor(array $items): array
	{
		$indexedItems = [];
		foreach ($items as $index => $item)
		{
			$indexedItems[] = [
				'item' => $item,
				'index' => $index,
				'sort' => $item->getSort() ?? PHP_INT_MAX,
			];
		}

		usort(
			$indexedItems,
			static fn(array $left, array $right) => [$left['sort'], $left['index']] <=> [$right['sort'], $right['index']],
		);

		return array_column($indexedItems, 'item');
	}
}
