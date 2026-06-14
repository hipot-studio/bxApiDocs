<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FlowCompositeActivity;
use Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity;
use Bitrix\Main\Localization\Loc;

final class CBPNodeWorkflowActivity extends FlowDirectedActivity implements IBPRootActivity
{
	private array $documentId = [];
	private ?int $workflowTemplateId = null;
	private ?int $templateUserId = null;
	protected array $documentType = [];

	private int $workflowStatus = CBPWorkflowStatus::Created;

	protected array $arVariables = [];
	protected array $arVariablesTypes = [];
	protected array $arFieldTypes = [];

	private const EXEC_LIMIT = 1000;
	private int $execCounter = 0;

	public function getDocumentId()
	{
		return $this->documentId;
	}

	public function setDocumentId($documentId)
	{
		$this->documentId = $documentId;
	}

	public function getWorkflowTemplateId()
	{
		return $this->workflowTemplateId;
	}

	public function setWorkflowTemplateId($workflowTemplateId)
	{
		$this->workflowTemplateId = $workflowTemplateId;
	}

	public function getTemplateUserId()
	{
		return $this->templateUserId;
	}

	public function setTemplateUserId($userId)
	{
		$this->templateUserId = (int)$userId;
	}

	public function getWorkflowStatus()
	{
		return $this->workflowStatus;
	}

	public function initialize()
	{
		$this->removeUnusedActivities();

		parent::initialize();
	}


	public function setWorkflowStatus($status)
	{
		$this->workflowStatus = $status;
		if ($status === CBPWorkflowStatus::Running && $this->{CBPDocument::PARAM_USE_FORCED_TRACKING})
		{
			/** @var CBPTrackingService $trackingService */
			$trackingService = $this->workflow->getService('TrackingService');
			$trackingService->setForcedMode($this->workflow->getInstanceId());
		}

		if ($status === CBPWorkflowStatus::Running)
		{
			$this->execCounter = 0;
		}

		if ($status === CBPWorkflowStatus::Completed || $status === CBPWorkflowStatus::Terminated)
		{
			$this->clearVariables();
			$this->clearProperties();

			foreach ($this->arEventsMap as $eventName)
			{
				/** @var CBPActivity $event */
				foreach ($eventName as $event)
				{
					if (method_exists($event, 'cancel'))
					{
						$event->cancel();
					}
				}
			}

			//Finalize workflow activities
			$this->workflow->finalizeActivity($this);

			/** @var CBPTrackingService $trackingService */
			$trackingService = $this->workflow->getService('TrackingService');
			if ($trackingService::shouldClearCompletedTracksOnly())
			{
				$trackingService->setCompletedByWorkflow($this->workflow->getInstanceId());
			}
		}

		/** @var CBPDocumentService $documentService */
		$documentService = $this->workflow->getService('DocumentService');
		$documentService->onWorkflowStatusChange(
			$this->getDocumentId(),
			$this->workflow->getInstanceId(),
			$status,
			$this
		);

		/** @var CBPStateService $stateService */
		$stateService = $this->workflow->getService('StateService');
		$stateService->onStatusChange($this->workflow->getInstanceId(), $status);
	}

	public function execute(): int
	{
		$triggerNames = $this->getStartActivityNames();
		if (!$triggerNames)
		{
			$this->setStateTitle(Loc::getMessage('BPNWA_EXECUTE_ERROR_NO_TRIGGER'));

			return CBPActivityExecutionStatus::Closed;
		}

		if ($this->executeByNames($this, $triggerNames))
		{
			$this->setStateTitle(Loc::getMessage('BPNWA_EXECUTE_IN_PROGRESS'));

			return CBPActivityExecutionStatus::Executing;
		}

		$this->setStateTitle(Loc::getMessage('BPNWA_EXECUTE_COMPLETED'));

		return CBPActivityExecutionStatus::Closed;
	}

	protected function onDeadEndReached(CBPActivity $lastActivity): void
	{}

	protected function close(): void
	{
		$this->setStateTitle(Loc::getMessage('BPNWA_EXECUTE_COMPLETED'));
		parent::close();
	}

	protected function getStartActivityNames(): array
	{
		$triggerEvent = $this->getRawProperty(CBPDocument::PARAM_TRIGGER_EVENT);
		if ($triggerEvent)
		{
			return [$triggerEvent];
		}

		// compatible behavior
		$eventType = $this->getRawProperty(CBPDocument::PARAM_DOCUMENT_EVENT_TYPE);
		$triggerType = match ($eventType)
		{
			CBPDocumentEventType::Create => 'CreateDocumentTrigger',
			CBPDocumentEventType::Edit => 'EditDocumentTrigger',
			default => 'ManualStartTrigger',
		};

		$triggerName = $this->findTriggerNameByType($triggerType);

		return $triggerName ? [$triggerName] : [];
	}

	private function findTriggerNameByType(string $type): ?string
	{
		$className = 'CBP' . $type;
		foreach ($this->arActivities as $activity)
		{
			if (get_class($activity) === $className)
			{
				return $activity->getName();
			}
		}

		return null;
	}

	protected function executeActivity(CBPActivity $sender, CBPActivity $activity, int $inputPort): void
	{
		$this->tryNextExecution();

		parent::executeActivity($sender, $activity, $inputPort);
	}

	public static function validateChild($childActivity, $bFirstChild = false, $childActivityData = [])
	{
		$errors = [];

		if (!empty($childActivityData['Children']))
		{
			static::includeActivityFile($childActivity);
			$child = static::createInstance($childActivity, 'XXX');
			if (!($child instanceof FlowCompositeActivity))
			{
				$errors[] = [
					'code' => 'WrongChildType',
					'message' => Loc::getMessage('BPNWA_VALIDATE_CHILD_ERROR'),
				];
			}
		}

		return [...$errors, ...parent::validateChild($childActivity, $bFirstChild, $childActivityData)];
	}

	private function setStateTitle(?string $title = '', string $state = 'Completed'): void
	{
		$stateService = $this->workflow->getService('StateService');
		$stateService->setState(
			$this->getWorkflowInstanceId(),
			[
				'STATE' => $state,
				'TITLE' => $title,
			],
			false
		);
	}

	private function tryNextExecution(): void
	{
		++$this->execCounter;
		if ($this->execCounter > self::EXEC_LIMIT)
		{
			throw new \CBPInvalidOperationException(Loc::getMessage('BPNWA_EXECUTE_ERROR_EXEC_LIMIT'));
		}
	}

	private function removeUnusedActivities(): void
	{
		[$usedNames, $usedLinks] = $this->findUsedNames();

		$this->arActivities = array_filter(
			$this->arActivities,
			static fn(CBPActivity $activity) => isset($usedNames[$activity->getName()])
		);
		$this->Links = $usedLinks;
	}

	/**
	 * @return array{0: array<string, true>, 1: list<array{0: string, 1: string}>}
	 */
	private function findUsedNames(): array
	{
		$stack = $this->getStartActivityNames();
		$usedLinks = [];
		$usedNames = array_fill_keys($stack, true);
		$linksMap = $this->buildLinksMap();

		$linkDelimiter = static::LINK_DELIMITER;

		while ($target = array_shift($stack))
		{
			if (!isset($linksMap[$target]))
			{
				continue;
			}

			foreach ($linksMap[$target] as $childName => $ports)
			{
				if (!isset($usedNames[$childName]))
				{
					$usedNames[$childName] = true;
					$stack[] = $childName;
				}

				foreach ($ports as [$t, $c])
				{
					$usedLinks[] = [
						"{$target}{$linkDelimiter}{$t}",
						"{$childName}{$linkDelimiter}{$c}",
					];
				}
			}
		}

		return [$usedNames, $usedLinks];
	}

	/**
	 * @return array<string, array<string, array{0: string, 1: string}>>
	 */
	private function buildLinksMap(): array
	{
		$map = [];
		$links = $this->getRawProperty(self::PARAM_LINKS);

		foreach ($links as $link)
		{
			[$parentName, $parentPort, $childName, $childPort] = $this->extractLink($link);
			$map[$parentName][$childName][] = [$parentPort, $childPort];
		}

		return $map;
	}

	/**
	 * @param array $link
	 * @return array<string>
	 */
	private function extractLink(array $link): array
	{
		[$parent, $child] = $link;
		[$parentName, $parentPort] = explode(self::LINK_DELIMITER, $parent);
		[$childName, $childPort] = explode(self::LINK_DELIMITER, $child);

		//fix wrong aux link direction
		if ($parentPort[0] === 't' && $childPort[0] === 'a')
		{
			return [$childName, $childPort, $parentName, $parentPort];
		}

		return [$parentName, $parentPort, $childName, $childPort];
	}
}
