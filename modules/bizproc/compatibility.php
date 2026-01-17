<?php

\Bitrix\Main\Loader::registerClassAliases([
	'Bitrix\Bizproc\WorkflowTemplateTable' => 'Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable',
	'Bitrix\Bizproc\WorkflowInstanceTable' => 'Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable',
	'Bitrix\Bizproc\WorkflowStateTable' => 'Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable',
	'CBPAllWorkflowPersister' => 'CBPWorkflowPersister',
	'CBPAllHistoryService' => 'CBPHistoryService',
	'CBPAllStateService' => 'CBPStateService',
	'CBPAllTaskService' => 'CBPTaskService',
	'CBPAllTrackingService' => 'CBPTrackingService',
	'CAllBPWorkflowTemplateLoader' => 'CBPWorkflowTemplateLoader',
	'CBPCalc' => 'Bitrix\Bizproc\Calc\Parser',
]);
