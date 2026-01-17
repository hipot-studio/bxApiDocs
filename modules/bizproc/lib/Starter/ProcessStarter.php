<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;

final class ProcessStarter extends BaseTypeStarter
{
	use ApplyRulesChecker;

	protected function checkFeature(?ModuleSettings $moduleSettings = null): bool
	{
		return \CBPRuntime::isFeatureEnabled();
	}

	protected function isOverLimited(?ModuleSettings $moduleSettings = null): bool
	{
		return false;
	}

	protected function runManualScenario(): bool
	{
		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Manual,
			\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
			\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
		];

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runOnAddScenario(): bool
	{
		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Create,
			\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
			\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
		];

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runOnUpdateScenario(): bool
	{
		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Edit,
			\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => (
				$this->document?->hasChangedFields() ? $this->document->getChangedFieldNames() : false
			),
			\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
		];

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runEventScenario(): bool
	{
		$result = true;
		foreach ($this->events as $event)
		{
			$startParameters = [
				\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => $event->getEventType(),
				\CBPDocument::PARAM_TAGRET_USER => $event->getUserId() > 0 ? 'user_' . $event->getUserId() : null,
				\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
				\CBPDocument::PARAM_DOCUMENT_TYPE => $event->getDocument()?->complexType ?: null,
				\CBPDocument::PARAM_TRIGGER_EVENT_DATA => $event->getParameters() ?? [],
			];

			if (!$this->runEvent($event, $startParameters))
			{
				$result = false;
			}
		}

		return $result;
	}

	private function runEvent(Event $event, array $startParameters): bool
	{
		$document = $event->getDocument();
		if ($document && !$document->getType())
		{
			return true;  // nothing to run
		}

		$code = $event->getCode();
		if (!$code || !$event->isProcessTrigger())
		{
			return true; // automation trigger
		}

		[$moduleId, $entity, $documentType] = $document?->complexType ?? Workflow::getComplexType();

		$query =
			WorkflowTemplateTriggerTable::query()
				->setSelect(['TEMPLATE_ID', 'APPLY_RULES', 'TRIGGER_NAME'])
				->where('TRIGGER_TYPE', $code)
				->setGroup(['TEMPLATE_ID', 'TEMPLATE.PARAMETERS'])
				->where('MODULE_ID', $moduleId)
				->where('ENTITY', $entity)
				->where('DOCUMENT_TYPE', $documentType)
		;

		if ($this->templateIds)
		{
			if (count($this->templateIds) === 1)
			{
				$query->where('TEMPLATE_ID', current($this->templateIds));
			}
			else
			{
				$query->whereIn('TEMPLATE_ID', $this->templateIds);
			}
		}

		$triggers = $query->exec();

		$result = true;
		while ($trigger = $triggers->fetch())
		{
			$workflowId = \CBPRuntime::generateWorkflowId();
			$complexId = $document->complexId ?? Workflow::getComplexId($workflowId);

			$templateId = (int)$trigger['TEMPLATE_ID'];
			$applyResult = $this->checkApplyRules(
				$code,
				$trigger['APPLY_RULES'] ?? [],
				$event->getParameters(),
				$templateId,
				$complexId
			);

			if (!$applyResult->isSuccess())
			{
				$this->errorCollection->add($applyResult->getErrors());

				continue;
			}

			$startParameters[\CBPDocument::PARAM_TRIGGER_EVENT] = $trigger['TRIGGER_NAME'];
			$startParameters[\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID] = $workflowId;

			if (\CBPHelper::isEqualDocumentEntity($complexId, Workflow::getComplexType()))
			{
				$startParameters[\CBPDocument::PARAM_IGNORE_SIMULTANEOUS_PROCESSES_LIMIT] = true;
			}

			// no parameters, no check constants
			$workflowId = $this->startWorkflow($templateId, $complexId, $startParameters);

			// no meta data

			if (!$workflowId)
			{
				$result = false;
			}
			else
			{
				$this->isTriggerApplied = true;
			}
		}

		return $result;
	}

	protected function runOnScriptScenario(): bool
	{
		// Script scenario is not supported, only as automation

		return true;
	}

	protected function getTemplatesByScenario(): array
	{
		// script scenario is not supported
		if (in_array($this->config->scenario, [Scenario::onEvent, Scenario::onScript], true))
		{
			return [];
		}

		$document = $this->document;
		if ($document && !$document->complexType)
		{
			return []; // no document, no templates for now
		}

		$complexDocumentType = $this->document?->complexType ?? Workflow::getComplexType();

		$filter = ['DOCUMENT_TYPE' => $complexDocumentType, 'ACTIVE' => 'Y'];
		switch ($this->config->scenario)
		{
			case Scenario::onManual:
				$filter['<AUTO_EXECUTE'] = \CBPDocumentEventType::Automation;
				break;
			case Scenario::onDocumentAdd:
			case Scenario::onDocumentInnerAdd:
				$filter['AUTO_EXECUTE'] = \CBPDocumentEventType::Create;
				break;
			case Scenario::onDocumentUpdate:
			case Scenario::onDocumentInnerUpdate:
				$filter['AUTO_EXECUTE'] = \CBPDocumentEventType::Edit;
				break;
			default:
				// nothing to add to filter
		}

		if ($this->templateIds)
		{
			if (count($this->templateIds) === 1)
			{
				$filter['=ID'] = current($this->templateIds);
			}
			else
			{
				$filter['@ID'] = $this->templateIds;
			}
		}

		$select = ['ID', 'PARAMETERS'];
		$list = \CBPWorkflowTemplateLoader::getList([], $filter, false, false, $select);

		$templates = [];
		while ($template = $list->fetch())
		{
			$templates[] = $template;
		}

		return $templates;
	}
}
