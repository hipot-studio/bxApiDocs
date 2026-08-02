<?php

namespace Bitrix\Bizproc\Script\Queue;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Script\Entity\EO_Script;
use Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument;
use Bitrix\Bizproc\Script\Entity\ScriptQueueTable;
use Bitrix\Bizproc\Script\Manager;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main;

final class Stepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';

	public function execute(array &$result)
	{
		$params = $this->getOuterParams();
		$queueId = reset($params);
		$scriptId = next($params);

		$counters = ScriptQueueTable::getDocumentCounters($queueId);

		$result['count'] = $counters['all'];
		$result['steps'] = $counters['completed'];

		if ($result['steps'] >= $result['count'])
		{
			ScriptQueueTable::markCompleted($queueId);

			return self::FINISH_EXECUTION;
		}

		$script = Manager::getById($scriptId);

		if (!$script)
		{
			ScriptQueueTable::delete($queueId);

			return self::FINISH_EXECUTION;
		}

		$document = ScriptQueueTable::getNextQueuedDocument($queueId);

		if (!$document)
		{
			ScriptQueueTable::markCompleted($queueId);

			return self::FINISH_EXECUTION;
		}

		ScriptQueueTable::markExecuting($queueId);

		return $this->executeDocument($document, $script);
	}

	private function executeDocument(EO_ScriptQueueDocument $document, EO_Script $script)
	{
		$document->setStatus(Status::EXECUTING)->save();

		$document->fillQueue();
		$queue = $document->getQueue();

		if (!$queue)
		{
			// queue is deleted

			return self::FINISH_EXECUTION;
		}

		$documentType = $documentId = [$script->getModuleId(), $script->getEntity(), $script->getDocumentType()];
		$documentId[2] = $document->getDocumentId();

		$workflowId = null;
		$errors = [];

		$canStart = \CBPDocument::canUserOperateDocument(
			\CBPCanUserOperateOperation::StartWorkflow,
			$queue->getStartedBy(),
			$documentId
		);

		if ($canStart)
		{
			$startParameters = $queue->getWorkflowParameters();
			if (!is_array($startParameters))
			{
				$startParameters = [];
			}

			$starter =
				Starter::getByScenario(Scenario::onScript)
					->setDocument(new DocumentDto($documentId, $documentType))
					->setParameters($startParameters)
					->setValidateParameters(false)
					->setUser($queue->getStartedBy())
					->setTemplateIds([$script->getWorkflowTemplateId()])
					->setContext(new ContextDto('bizproc'))
			;
			$result = $starter->start();
			$workflowId = current($result->getWorkflowIds()) ?: null;
			if (!$result->isSuccess())
			{
				$errors = array_map(static fn($message) => ['message' => $message], $result->getErrorMessages());
			}
			}
			else
			{
				$errors[] = ['message' => ErrorMessage::START_ACCESS_DENIED->get()];
			}

		if (!$workflowId && !$errors)
		{
			$errors[] = ['message' => ErrorMessage::CREATE_WORKFLOW->get()];
		}

		if ($workflowId)
		{
			$document->setWorkflowId($workflowId);
			$document->setStatus(Status::COMPLETED);
		}

		if ($errors)
		{
			$document->setStatus(Status::FAULT);
			$document->setStatusMessage(reset($errors)['message']);
		}

		$document->save();

		return self::CONTINUE_EXECUTION;
	}

	public static function getTitle()
	{
		return "Script queues";
	}
}
