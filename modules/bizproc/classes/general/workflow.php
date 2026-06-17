<?php

use Bitrix\Bizproc;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

/**
 * Workflow instance.
 *
 * @method \CBPSchedulerService getSchedulerService()
 * @method \CBPStateService getStateService()
 * @method \CBPTrackingService getTrackingService()
 * @method \CBPTaskService getTaskService()
 * @method \CBPHistoryService getHistoryService()
 * @method \CBPDocumentService getDocumentService()
 * @method Bizproc\Service\Analytics getAnalyticsService()
 * @method Bizproc\Service\User getUserService()
 * @method Bizproc\Service\AiDescription getAiDescriptionService()
 */
class CBPWorkflow
{
	private bool $isNew = false;
	private bool $isAbandoned = false;
	private string $instanceId;

	protected CBPRuntime $runtime;
	protected CBPWorkflowPersister $persister;

	protected ?CBPCompositeActivity $rootActivity;

	protected array $activitiesQueue = [];
	protected array $eventsQueue = [];

	private array $activitiesNamesMap = [];

	/************************  PROPERTIES  *******************************/

	public function getInstanceId()
	{
		return $this->instanceId;
	}

	/**
	 * @return CBPRuntime
	 */
	public function getRuntime()
	{
		return $this->runtime;
	}

	public function getRootActivity(): CBPCompositeActivity
	{
		return $this->rootActivity;
	}

	private function getWorkflowStatus()
	{
		return $this->rootActivity->getWorkflowStatus();
	}

	protected function setWorkflowStatus($newStatus)
	{
		$this->rootActivity->setWorkflowStatus($newStatus);
		$this->getRuntime()->onWorkflowStatusChanged($this->getInstanceId(), $newStatus);
		$this->syncStatus($newStatus);
	}

	public function getService($name)
	{
		return $this->runtime->getService($name);
	}

	public function __call($name, $arguments)
	{
		if (preg_match('|^get([a-z]+)service$|i', $name, $matches))
		{
			return $this->getService($matches[1] . 'Service');
		}

		throw new Main\SystemException("Unknown method `{$name}`");
	}

	public function getDocumentId()
	{
		return $this->rootActivity->getDocumentId();
	}

	public function getDocumentType()
	{
		return $this->rootActivity->getDocumentType();
	}

	public function getTemplateId(): int
	{
		return (int)$this->rootActivity->getWorkflowTemplateId();
	}

	public function getStartedBy(): ?int
	{
		$startedBy = (int)CBPHelper::stripUserPrefix($this->rootActivity->{\CBPDocument::PARAM_TAGRET_USER});

		return $startedBy ?: null;
	}

	public function getPersister(): CBPWorkflowPersister
	{
		return $this->persister;
	}

	/************************  CONSTRUCTORS  ****************************************************/

	/**
	 * Public constructor initializes a new workflow instance with the specified ID.
	 *
	 * @param mixed $instanceId - ID of the new workflow instance.
	 * @param mixed $runtime - Runtime object.
	 */
	public function __construct($instanceId, CBPRuntime $runtime)
	{
		if (!$instanceId)
		{
			throw new Exception("instanceId");
		}

		$this->instanceId = $instanceId;
		$this->runtime = $runtime;
		$this->persister = CBPWorkflowPersister::GetPersister();
	}

	/**
	 * Remove workflow object from serialized data
	 * @return array
	 */
	public function __sleep()
	{
		return [];
	}

	/************************  CREATE / LOAD WORKFLOW  ****************************************/

	public function initialize(
		CBPActivity $rootActivity,
		$documentId,
		$workflowParameters = [],
		$workflowVariablesTypes = [],
		$workflowParametersTypes = [],
		$workflowTemplateId = 0,
	)
	{
		$this->rootActivity = $rootActivity;
		$rootActivity->setWorkflow($this);
		if (method_exists($rootActivity, 'setWorkflowTemplateId'))
		{
			$rootActivity->setWorkflowTemplateId($workflowTemplateId);
		}

		if (method_exists($rootActivity, 'setTemplateUserId'))
		{
			$rootActivity->setTemplateUserId(
				CBPWorkflowTemplateLoader::getTemplateUserId($workflowTemplateId),
			);
		}

		$arDocumentId = CBPHelper::parseDocumentId($documentId);

		$rootActivity->setDocumentId($arDocumentId);

		$documentService = $this->getService("DocumentService");
		$documentType = $workflowParameters[CBPDocument::PARAM_DOCUMENT_TYPE]
			?? $documentService->getDocumentType($arDocumentId);

		unset($workflowParameters[CBPDocument::PARAM_DOCUMENT_TYPE]);

		if ($documentType !== null)
		{
			$rootActivity->setDocumentType($documentType);
			$rootActivity->setFieldTypes($documentService->getDocumentFieldTypes($documentType));
		}

		$rootActivity->setProperties($workflowParameters);

		$rootActivity->setVariablesTypes($workflowVariablesTypes);
		if (is_array($workflowVariablesTypes))
		{
			foreach ($workflowVariablesTypes as $k => $v)
			{
				$variableValue = $v["Default"] ?? null;
				if ($documentType && $fieldTypeObject = $documentService->getFieldTypeObject($documentType, $v))
				{
					$fieldTypeObject->setDocumentId($arDocumentId);
					$variableValue = $fieldTypeObject->internalizeValue('Variable', $variableValue);
				}

				//set defaults on start
				$rootActivity->setVariable($k, $variableValue);
			}
		}

		$rootActivity->setPropertiesTypes($workflowParametersTypes);
	}

	public function reload(CBPActivity $rootActivity)
	{
		$this->rootActivity = $rootActivity;
		$rootActivity->setWorkflow($this);

		switch ($this->getWorkflowStatus())
		{
			case CBPWorkflowStatus::Completed:
			case CBPWorkflowStatus::Terminated:
				throw new Exception("InvalidAttemptToLoad");
		}
	}

	/************************  EXECUTE WORKFLOW  ************************************************/

	public function startLater(int $delay = 0)
	{
		if ($this->getWorkflowStatus() !== CBPWorkflowStatus::Created)
		{
			throw new Exception("CanNotStartInstanceTwice");
		}

		$this->isNew = true;
		$this->initializeRoot();
		$this->setWorkflowStatus(CBPWorkflowStatus::Suspended);
		$this->save();
		$this->getSchedulerService()->subscribeStartWorkflow($this->getInstanceId(), $delay);
	}

	/**
	 * Starts new workflow instance.
	 */
	public function start(): void
	{
		if ($this->getWorkflowStatus() !== CBPWorkflowStatus::Created)
		{
			throw new Exception("CanNotStartInstanceTwice");
		}

		$this->isNew = true;
		$this->initializeRoot();
		$this->run();
	}

	private function initializeRoot(): void
	{
		$this->initializeActivity($this->rootActivity);
		$this->rootActivity->setReadOnlyData(
			$this->rootActivity->pullProperties()
		);
	}

	/**
	 * Resume existing workflow.
	 */
	public function resume(): void
	{
		if ($this->getWorkflowStatus() !== CBPWorkflowStatus::Suspended)
		{
			throw new Exception("CanNotResumeInstance");
		}

		$this->run();
	}

	public function save()
	{
		$this->persister->saveWorkflow($this->rootActivity, true);
	}

	/**
	 * @throws Main\SystemException
	 * @throws CBPInvalidOperationException
	 * @throws Bizproc\Internal\Exceptions\Debugger\DebuggerException
	 */
	private function run(): void
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::run',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_PROCESS') ?? '',
			[
				'status' => CBPWorkflowStatus::out($this->getWorkflowStatus()),
				'instance_id' => $this->getInstanceId(),
				'template_id' => $this->getTemplateId(),
				'document_id' => $this->getDocumentId(),
				'document_type' => $this->getDocumentType(),
			],
		);

		try
		{
			$this->setWorkflowStatus(CBPWorkflowStatus::Running);

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::run',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_STATUS_SET') ?? '',
				[
					'status' => CBPWorkflowStatus::out($this->getWorkflowStatus()),
					'is_new' => $this->isNew,
				],
			);

			if ($this->rootActivity->executionStatus === CBPActivityExecutionStatus::Initialized)
			{
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Condition,
					'CBPWorkflow::run',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_NEW_INSTANCE') ?? '',
					[
						'instance_id' => $this->getInstanceId(),
						'document_id' => $this->rootActivity->getDocumentId(),
						'document_type' => $this->rootActivity->getDocumentType(),
						'title' => $this->rootActivity->getTitle(),
						'type' => $this->rootActivity->getType(),
						'document_event_type' => $this->rootActivity->getDocumentEventType(),
					]
				);

				$this->executeActivity($this->rootActivity);
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Condition,
					'CBPWorkflow::run',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_ROOT_STARTING') ?? '',
				);
			}

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::run',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_QUEUE_START') ?? '',
			);

			$this->runQueue();
		}
		catch (Exception $e)
		{
			$this->terminate($e);

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				'CBPWorkflow::run::exception::' . $e::class,
				$e->getMessage(),
				[
					'error_code' => $e->getCode(),
					'error_file' => $e->getFile(),
					'error_line' => $e->getLine(),
					'error_trace' => $e->getTraceAsString(),
				],
			);

			throw $e;
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::run',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_STATUS_CURRENT') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($this->rootActivity->executionStatus),
			],
		);

		if ($this->rootActivity->executionStatus === CBPActivityExecutionStatus::Closed)
		{
			$this->setWorkflowStatus(CBPWorkflowStatus::Completed);
		}
		elseif ($this->getWorkflowStatus() === CBPWorkflowStatus::Running)
		{
			$this->setWorkflowStatus(CBPWorkflowStatus::Suspended);
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Condition,
			'CBPWorkflow::run',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_RUN_STATUS_AFTER') ?? '',
			[
				'status' => CBPWorkflowStatus::out($this->getWorkflowStatus()),
			],
		);

		$this->save();
	}

	public function isNew()
	{
		return $this->isNew || ($this->getWorkflowStatus() === CBPWorkflowStatus::Created);
	}

	public function abandon(): void
	{
		$this->isAbandoned = true;
	}

	public function isAbandoned(): bool
	{
		return $this->isAbandoned;
	}

	public function isFinished(): bool
	{
		if ($this->isAbandoned())
		{
			return true;
		}

		return CBPWorkflowStatus::isFinished((int)$this->getWorkflowStatus());
	}

	/**********************  EXTERNAL EVENTS  **************************************************************/

	/**
	 * Resume the workflow instance and transfer the specified event to it.
	 *
	 * @param string $eventName - Event name.
	 * @param array $eventParameters - Event parameters.
	 */
	public function sendExternalEvent(string $eventName, array $eventParameters = []): void
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::sendExternalEvent',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_SEND_EVENT') ?? '',
			[
				'event_name' => $eventName,
				'event_parameters' => $eventParameters,
				'workflow_status' => CBPWorkflowStatus::out($this->getWorkflowStatus()),
				'instance_id' => $this->getInstanceId(),
			],
		);

		$this->addEventToQueue($eventName, $eventParameters);

		if ($this->getWorkflowStatus() !== CBPWorkflowStatus::Running)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::sendExternalEvent',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_SEND_EVENT_RESUME') ?? '',
				[
					'event_name' => $eventName,
				],
			);

			$this->resume();
		}
	}

	/***********************  SEARCH ACTIVITY BY NAME  ****************************************************/

	private function fillNameActivityMapInternal(CBPActivity $activity)
	{
		$this->activitiesNamesMap[$activity->getName()] = $activity;

		if ($activity instanceof \CBPCompositeActivity)
		{
			$arSubActivities = $activity->collectNestedActivities();
			foreach ($arSubActivities as $subActivity)
			{
				$this->fillNameActivityMapInternal($subActivity);
			}
		}
	}

	private function fillNameActivityMap()
	{
		if (!is_array($this->activitiesNamesMap))
		{
			$this->activitiesNamesMap = [];
		}

		if (count($this->activitiesNamesMap) > 0)
		{
			return;
		}

		$this->fillNameActivityMapInternal($this->rootActivity);
	}

	/**
	 * Returns activity by its name.
	 *
	 * @param mixed $activityName - Activity name.
	 *
	 * @return CBPActivity - Returns activity object or null if activity is not found.
	 * @throws CBPArgumentNullException
	 */
	public function getActivityByName($activityName)
	{
		if (empty($activityName))
		{
			throw new CBPArgumentNullException('activityName');
		}

		$activity = null;

		$this->fillNameActivityMap();

		if (array_key_exists($activityName, $this->activitiesNamesMap))
		{
			$activity = $this->activitiesNamesMap[$activityName];
		}

		return $activity;
	}

	/************************  ACTIVITY EXECUTION  *************************************************/

	/**
	 * Initializes the specified activity by calling its method Initialize.
	 *
	 * @param CBPActivity $activity
	 */
	public function initializeActivity(CBPActivity $activity)
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::initializeActivity',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_INIT_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle(),
				],
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Initialized)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				'CBPWorkflow::initializeActivity',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_INIT_WRONG_STATUS') ?? '',
				[
					'expected_status' => CBPActivityExecutionStatus::out(CBPActivityExecutionStatus::Initialized),
					'actual_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				],
			);

			throw new Exception("InvalidInitializingState");
		}

		$activity->initialize();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::initializeActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_INIT_SUCCESS') ?? '',
			[
				'name' => $activity->getName(),
			],
		);
	}

	/**
	 * Plans specified activity for execution.
	 *
	 * @param CBPActivity $activity - Activity object.
	 * @param mixed $eventParameters - Optional parameters.
	 *
	 * @throws CBPInvalidOperationException
	 */
	public function executeActivity(CBPActivity $activity, array $eventParameters = []): ExecutionPayload
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::executeActivity',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_EXEC_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle(),
				],
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				'event_parameters' => $eventParameters,
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Initialized)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				'CBPWorkflow::executeActivity',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_EXEC_WRONG_STATUS') ?? '',
				[
					'expected_status' => CBPActivityExecutionStatus::out(CBPActivityExecutionStatus::Initialized),
					'actual_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				],
			);

			throw new CBPInvalidOperationException('InvalidExecutionState');
		}

		$payload = new ExecutionPayload();

		$activity->setStatus(CBPActivityExecutionStatus::Executing, $eventParameters);
		$this->addItemToQueue([$activity, CBPActivityExecutorOperationType::Execute, $payload]);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::executeActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_EXEC_QUEUED') ?? '',
			[
				'name' => $activity->getName(),
				'new_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		return $payload;
	}

	/**
	 * Close specified activity.
	 *
	 * @param CBPActivity $activity - Activity object.
	 * @param mixed $arEventParameters - Optional parameters.
	 *
	 * @throws CBPInvalidOperationException
	 */
	public function closeActivity(CBPActivity $activity, $arEventParameters = [])
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::closeActivity',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_CLOSE_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle(),
				],
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				'event_parameters' => $arEventParameters,
			],
		);

		switch ($activity->executionStatus)
		{
			case CBPActivityExecutionStatus::Executing:
				$activity->markCompleted($arEventParameters);
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					'CBPWorkflow::closeActivity',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_CLOSE_COMPLETED') ?? '',
					['name' => $activity->getName()],
				);

				return;

			case CBPActivityExecutionStatus::Canceling:
				$activity->markCanceled($arEventParameters);
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					'CBPWorkflow::closeActivity',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_CLOSE_CANCELED') ?? '',
					['name' => $activity->getName()],
				);

				return;

			case CBPActivityExecutionStatus::Closed:
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					'CBPWorkflow::closeActivity',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_CLOSE_ALREADY') ?? '',
					['name' => $activity->getName()],
				);

				return;

			case CBPActivityExecutionStatus::Faulting:
				$activity->markFaulted($arEventParameters);
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Error,
					'CBPWorkflow::closeActivity',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_CLOSE_FAULTED') ?? '',
					['name' => $activity->getName()],
				);

				return;
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Error,
			'CBPWorkflow::closeActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CLOSE_WRONG_STATUS') ?? '',
			[
				'name' => $activity->getName(),
				'actual_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		throw new CBPInvalidOperationException('InvalidClosingState');
	}

	/**
	 * Cancel specified activity.
	 *
	 * @param CBPActivity $activity - Activity object.
	 * @param mixed $arEventParameters - Optional parameters.
	 */
	public function cancelActivity(CBPActivity $activity, $arEventParameters = [])
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::cancelActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_ACTIVITY', ['#TITLE#' => $activity->getTitle()]) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				'event_parameters' => $arEventParameters,
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Executing)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				'CBPWorkflow::cancelActivity',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_WRONG_STATUS') ?? '',
				[
					'expected_status' => CBPActivityExecutionStatus::out(CBPActivityExecutionStatus::Executing),
					'actual_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				],
			);

			throw new Exception("InvalidCancelingState");
		}

		$activity->setStatus(CBPActivityExecutionStatus::Canceling, $arEventParameters);
		$this->addItemToQueue([$activity, CBPActivityExecutorOperationType::Cancel]);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::cancelActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_QUEUED') ?? '',
			[
				'name' => $activity->getName(),
				'new_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);
	}

	public function faultActivity(CBPActivity $activity, Exception $e, $arEventParameters = [])
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Error,
			'CBPWorkflow::faultActivity',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_FAULT_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle(),
				],
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				'exception' => [
					'class' => $e::class,
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				],
				'event_parameters' => $arEventParameters,
			],
		);

		if ($activity->executionStatus === CBPActivityExecutionStatus::Closed)
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::faultActivity',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_FAULT_CLOSED_PARENT') ?? '',
				[
					'name' => $activity->getName(),
					'has_parent' => $activity->parent !== null,
				],
			);

			if ($activity->parent === null)
			{
				$this->Terminate($e);
			}
			else
			{
				$this->FaultActivity($activity->parent, $e, $arEventParameters);
			}
		}
		else
		{
			$activity->setStatus(CBPActivityExecutionStatus::Faulting);
			$this->addItemToQueue([$activity, CBPActivityExecutorOperationType::HandleFault, $e]);

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::faultActivity',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_FAULT_QUEUED') ?? '',
				[
					'name' => $activity->getName(),
					'new_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
				],
			);
		}
	}

	/************************  ACTIVITIES QUEUE  ***********************************************/

	private function addItemToQueue($item)
	{
		$this->activitiesQueue[] = $item;
	}

	protected function runQueue()
	{
		$canRun = $this->runStep();

		while ($canRun)
		{
			$canRun = $this->runStep();
		}
	}

	protected function runStep(): bool
	{
		if (empty($this->activitiesQueue))
		{
			$this->ProcessQueuedEvents();
		}

		$item = array_shift($this->activitiesQueue);

		if ($item === null)
		{
			return false;
		}

		try
		{
			$this->runQueuedItem($item[0], $item[1], $item[2] ?? null);
		}
		catch (Exception $e)
		{
			$this->getRuntime()->getDebugSessionService()?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Error,
				'CBPWorkflow::runStep::exception::' . $e::class,
				$e->getMessage(),
				[
					'error_code' => $e->getCode(),
					'error_file' => $e->getFile(),
					'error_line' => $e->getLine(),
					'error_trace' => $e->getTraceAsString(),
				],
			);

			$this->faultActivity($item[0], $e);
		}

		return !$this->isFinished();
	}

	/**
	 * @throws Exception
	 */
	private function runQueuedItem(CBPActivity $activity, $activityOperation, Exception|ExecutionPayload|null $payload = null): void
	{
		match ($activityOperation)
		{
			CBPActivityExecutorOperationType::Execute => $this->runExecuteActivityOperation($activity, $payload),
			CBPActivityExecutorOperationType::Cancel => $this->runCancelActivityOperation($activity),
			CBPActivityExecutorOperationType::HandleFault => $this->runHandleFaultActivityOperation($activity, $payload),
		};
	}

	/**
	 * @param CBPActivity $activity
	 * @param ExecutionPayload|null $payload
	 * @return void
	 * @throws CBPArgumentNullException
	 * @throws CBPInvalidOperationException
	 * @throws Main\SystemException
	 */
	private function runExecuteActivityOperation(CBPActivity $activity, ?ExecutionPayload $payload = null): void
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runExecuteActivityOperation',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_RUN_EXEC_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle()
				]
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'properties' => $activity->arProperties,
				'property_types' => $activity->arPropertiesTypes,
				'payload' => [
					'input_port' => $payload?->getInputPort(),
					'parent_name' => $payload?->getParentName(),
					'parent_port' => $payload?->getParentPort(),
				],
				'instance_id' => $this->getInstanceId(),
				'execution_status' => $activity->executionStatus,
				'execution_result' => $activity->executionResult,
			],
		);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Condition,
			'CBPWorkflow::runExecuteActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_EXEC_OP_CURRENT_STATUS') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Executing)
		{
			return;
		}

		$newStatus = CBPActivityExecutionStatus::Closed;
		if ($activity->isActivated())
		{
			/** @var CBPTrackingService $trackingService */
			$trackingService = $this->getService('TrackingService');
			$trackingService->write(
				$this->getInstanceId(),
				CBPTrackingType::ExecuteActivity,
				$activity->getName(),
				$activity->executionStatus,
				$activity->executionResult,
				$activity->getTitle(),
				'',
			);
			$newStatus = $activity->executeWithPayload($payload ?? new ExecutionPayload());

			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Condition,
				'CBPWorkflow::runExecuteActivityOperation',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_EXEC_OP_ACTIVATED') ?? '',
				[
					'input_port' => $payload?->getInputPort(),
					'parent_name' => $payload?->getParentName(),
					'parent_port' => $payload?->getParentPort(),
				],
			);
		}

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runExecuteActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_EXEC_OP_STATUS') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		if ($newStatus === CBPActivityExecutionStatus::Closed)
		{
			$this->closeActivity($activity);

			return;
		}

		if ($newStatus === CBPActivityExecutionStatus::Cancelled)
		{
			$activity->setStatus(CBPActivityExecutionStatus::Canceling);
			$this->closeActivity($activity);

			return;
		}

		if ($newStatus !== CBPActivityExecutionStatus::Executing)
		{
			throw new Exception('InvalidExecutionStatus');
		}
	}

	/**
	 * @param CBPActivity $activity
	 * @return void
	 * @throws Exception
	 */
	private function runCancelActivityOperation(CBPActivity $activity): void
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runCancelActivityOperation::' . $activity->getName(),
			 $activity->getTitle(),
			[
				'type' => $activity->getType(),
				'properties' => $activity->arProperties,
				'property_types' => $activity->arPropertiesTypes,
				'instance_id' => $this->getInstanceId(),
				'execution_status' => $activity->executionStatus,
				'execution_result' => $activity->executionResult,
			],
		);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runCancelActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_OP_STATUS') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Canceling)
		{
			return;
		}

		/** @var CBPTrackingService $trackingService */
		$trackingService = $this->getService("TrackingService");
		$trackingService->write(
			$this->getInstanceId(),
			CBPTrackingType::CancelActivity,
			$activity->getName(),
			$activity->executionStatus,
			$activity->executionResult,
			$activity->getTitle(),
		);

		$newStatus = $activity->cancel();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runCancelActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_OP_NEW_STATUS') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($newStatus),
			],
		);

		if ($newStatus === CBPActivityExecutionStatus::Closed)
		{
			$this->closeActivity($activity);
		}
		elseif ($newStatus !== CBPActivityExecutionStatus::Canceling)
		{
			throw new Exception("InvalidExecutionStatus");
		}
	}

	/**
	 * @param CBPActivity $activity
	 * @param Exception|null $exception
	 * @return void
	 * @throws Exception
	 */
	private function runHandleFaultActivityOperation(CBPActivity $activity, ?Exception $exception): void
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runHandleFaultActivityOperation::' . $activity->getName(),
			$activity->getTitle(),
			[
				'type' => $activity->getType(),
				'properties' => $activity->arProperties,
				'property_types' => $activity->arPropertiesTypes,
				'instance_id' => $this->getInstanceId(),
				'execution_status' => $activity->executionStatus,
				'execution_result' => $activity->executionResult,
				'exception' => [
					'class' => $exception::class,
					'message' => $exception?->getMessage(),
					'code' => $exception?->getCode(),
				],
			],
		);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runHandleFaultActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_CANCEL_OP_STATUS') ?? '',
			[
				'status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		if ($activity->executionStatus !== CBPActivityExecutionStatus::Faulting)
		{
			return;
		}

		/** @var CBPTrackingService $trackingService */
		$trackingService = $this->getService("TrackingService");
		$trackingService->write(
			$this->getInstanceId(),
			CBPTrackingType::FaultActivity,
			$activity->getName(),
			$activity->executionStatus,
			$activity->executionResult,
			$activity->getTitle(),
			($exception ? ($exception->getCode() ? "[" . $exception->getCode() . "] " : '') . $exception->getMessage() : ""),
		);

		$newStatus = $activity->handleFault($exception);

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::runHandleFaultActivityOperation',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_FAULT_OP_DONE') ?? '',
			[
				'status_label' => CBPActivityExecutionStatus::out($newStatus),
				'status_value' => $newStatus,
			],
		);

		if ($newStatus === CBPActivityExecutionStatus::Closed)
		{
			$this->closeActivity($activity);
		}
		elseif ($newStatus !== CBPActivityExecutionStatus::Faulting)
		{
			throw new Exception("InvalidExecutionStatus");
		}
	}

	public function terminate(?Exception $e = null, $stateTitle = '')
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Error,
			'CBPWorkflow::terminate',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_TERMINATE') ?? '',
			[
				'instance_id' => $this->getInstanceId(),
				'state_title' => $stateTitle ?: Loc::getMessage("BPCGWF_TERMINATED_MSGVER_1"),
				'exception' => $e ? [
					'class' => $e::class,
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				] : null,
			],
		);

		CBPTaskService::deleteByWorkflow($this->getInstanceId(), \CBPTaskStatus::Running);

		$this->setWorkflowStatus(CBPWorkflowStatus::Terminated);

		$this->save();

		/** @var CBPStateService $stateService */
		$stateService = $this->GetService("StateService");
		$stateService->SetState(
			$this->instanceId,
			[
				"STATE" => "Terminated",
				"TITLE" => $stateTitle ?: Loc::getMessage("BPCGWF_TERMINATED_MSGVER_1"),
				"PARAMETERS" => [],
			],
			false,
		);

		if ($e)
		{
			/** @var CBPTrackingService $trackingService */
			$trackingService = $this->getService("TrackingService");
			$trackingService->write(
				$this->instanceId,
				CBPTrackingType::FaultActivity,
				"none",
				CBPActivityExecutionStatus::Faulting,
				CBPActivityExecutionResult::Faulted,
				Loc::getMessage('BPCGWF_EXCEPTION_TITLE'),
				($e->getCode() ? "[" . $e->getCode() . "] " : '') . $e->getMessage(),
			);
		}
	}

	/**
	 * @param CBPActivity $activity
	 * @throws CBPArgumentNullException
	 * @throws Exception
	 */
	public function finalizeActivity(CBPActivity $activity)
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::finalizeActivity',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_FINALIZE_ACTIVITY',
				[
					'#TITLE#' => $activity->getTitle()
				]
			) ?? '',
			[
				'name' => $activity->getName(),
				'type' => $activity->getType(),
				'execution_status' => CBPActivityExecutionStatus::out($activity->executionStatus),
			],
		);

		$activity->finalize();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::finalizeActivity',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_FINALIZE_SUCCESS') ?? '',
			[
				'name' => $activity->getName(),
			],
		);
	}

	/************************  EVENTS QUEUE  ********************************************************/

	private function addEventToQueue($eventName, $arEventParameters = [])
	{
		$this->eventsQueue[] = [$eventName, $arEventParameters];
	}

	private function processQueuedEvents()
	{
		while (true)
		{
			$event = array_shift($this->eventsQueue);
			if ($event === null)
			{
				return;
			}

			[$eventName, $eventParameters] = $event;

			$this->processQueuedEvent($eventName, $eventParameters);
		}
	}

	private function processQueuedEvent($eventName, $eventParameters = [])
	{
		$debugSessionService = $this->runtime->getDebugSessionService();

		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::processQueuedEvent',
			Loc::getMessage('BPCGWF_DEBUG_TRACE_EVENT_PROCESSING') ?? '',
			[
				'event_name' => $eventName,
				'event_parameters' => $eventParameters,
			],
		);

		if (!array_key_exists($eventName, $this->rootActivity->arEventsMap))
		{
			$debugSessionService?->addTrace(
				Bizproc\Internal\Entity\Debugger\TraceType::Log,
				'CBPWorkflow::processQueuedEvent',
				Loc::getMessage('BPCGWF_DEBUG_TRACE_EVENT_NOT_FOUND') ?? '',
				[
					'event_name' => $eventName,
					'available_events' => array_keys($this->rootActivity->arEventsMap ?? []),
				],
			);

			return;
		}

		$handlersCount = count($this->rootActivity->arEventsMap[$eventName]);
		$debugSessionService?->addTrace(
			Bizproc\Internal\Entity\Debugger\TraceType::Log,
			'CBPWorkflow::processQueuedEvent',
			Loc::getMessage(
				'BPCGWF_DEBUG_TRACE_EVENT_HANDLERS_COUNT',
				[
					'#COUNT#' => $handlersCount,
				],
			) ?? '',
			[
				'event_name' => $eventName,
				'handlers_count' => $handlersCount,
			],
		);

		foreach ($this->rootActivity->arEventsMap[$eventName] as $eventHandler)
		{
			if (!empty($eventParameters['DebugEvent']) && $eventHandler instanceof IBPActivityDebugEventListener)
			{
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					'CBPWorkflow::processQueuedEvent',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_EVENT_DEBUG_HANDLER') ?? '',
					[
						'event_name' => $eventName,
						'handler_class' => get_class($eventHandler),
					],
				);

				$eventHandler->onDebugEvent($eventParameters);

				continue;
			}

			if ($eventHandler instanceof IBPActivityExternalEventListener)
			{
				$debugSessionService?->addTrace(
					Bizproc\Internal\Entity\Debugger\TraceType::Log,
					'CBPWorkflow::processQueuedEvent',
					Loc::getMessage('BPCGWF_DEBUG_TRACE_EVENT_EXTERNAL_HANDLER') ?? '',
					[
						'event_name' => $eventName,
						'handler_class' => get_class($eventHandler),
					],
				);

				$eventHandler->onExternalEvent($eventParameters);
			}
		}
	}

	private function syncStatus(int $status): void
	{
		if ($status < CBPWorkflowStatus::Completed) // skip Created and Running
		{
			return;
		}

		$hasUsers = WorkflowUserTable::syncOnWorkflowUpdated($this, $status);
		if ($hasUsers)
		{
			WorkflowFilterTable::addByWorkflowId($this->getInstanceId());
		}
	}

	/**
	 * Add new event handler to the specified event.
	 *
	 * @param mixed $eventName - Event name.
	 * @param IBPActivityExternalEventListener $eventHandler - Event handler.
	 */
	public function addEventHandler($eventName, IBPActivityExternalEventListener $eventHandler)
	{
		if (!is_array($this->rootActivity->arEventsMap))
		{
			$this->rootActivity->arEventsMap = [];
		}

		if (!array_key_exists($eventName, $this->rootActivity->arEventsMap))
		{
			$this->rootActivity->arEventsMap[$eventName] = [];
		}

		$this->rootActivity->arEventsMap[$eventName][] = $eventHandler;
	}

	public function getEventsMap(): array
	{
		return is_array($this->rootActivity->arEventsMap) ? $this->rootActivity->arEventsMap : [];
	}

	/**
	 * Remove the event handler from the specified event.
	 *
	 * @param mixed $eventName - Event name.
	 * @param IBPActivityExternalEventListener $eventHandler - Event handler.
	 */
	public function removeEventHandler($eventName, IBPActivityExternalEventListener $eventHandler)
	{
		if (!is_array($this->rootActivity->arEventsMap))
		{
			$this->rootActivity->arEventsMap = [];
		}

		if (!array_key_exists($eventName, $this->rootActivity->arEventsMap))
		{
			$this->rootActivity->arEventsMap[$eventName] = [];
		}

		$idx = array_search($eventHandler, $this->rootActivity->arEventsMap[$eventName], true);
		if ($idx !== false)
		{
			unset($this->rootActivity->arEventsMap[$eventName][$idx]);
		}

		if (count($this->rootActivity->arEventsMap[$eventName]) <= 0)
		{
			unset($this->rootActivity->arEventsMap[$eventName]);
		}
	}

	public function isDebug(): bool
	{
		return false;
	}
}
