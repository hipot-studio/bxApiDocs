<?php

use Bitrix\Bizproc\Internal\Entity\Debugger\TraceType;
use Bitrix\Main\Localization\Loc;

abstract class CBPCompositeActivity extends CBPActivity
{
	/**
	 * @var CBPActivity[] $arActivities
	 */
	protected $arActivities = [];
	protected $readOnlyData = [];

	public function setWorkflow(CBPWorkflow $workflow)
	{
		$debugSessionService = $workflow->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::setWorkflow',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_SET_WF', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		parent::setWorkflow($workflow);

		foreach ($this->arActivities as $activity)
		{
			if (!method_exists($activity, 'setWorkflow'))
			{
				$debugSessionService?->addTrace(
					TraceType::Error,
					'CBPCompositeActivity::setWorkflow',
					Loc::getMessage('BPCGCA_DEBUG_TRACE_SET_WF_NO_METHOD') ?? '',
					[
						'parent_activity' => $this->getName(),
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
					],
				);

				throw new Exception('ActivitySetWorkflow');
			}
			$activity->setWorkflow($workflow);

			$debugSessionService?->addTrace(
				TraceType::Log,
				'CBPCompositeActivity::setWorkflow',
				Loc::getMessage('BPCGCA_DEBUG_TRACE_SET_WF_CHILD_DONE') ?? '',
				[
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);
		}

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::setWorkflow',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_SET_WF_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'processed_count' => count($this->arActivities),
			],
		);
	}

	public function unsetWorkflow()
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::unsetWorkflow',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_UNSET_WF', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		parent::unsetWorkflow();

		foreach ($this->arActivities as $activity)
		{
			if (method_exists($activity, 'SetWorkflow'))
			{
				$activity->unsetWorkflow();

				$debugSessionService?->addTrace(
					TraceType::Log,
					'CBPCompositeActivity::unsetWorkflow',
					Loc::getMessage('BPCGCA_DEBUG_TRACE_UNSET_WF_CHILD_DONE') ?? '',
					[
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
					],
				);
			}
		}
	}

	public function setReadOnlyData(array $data)
	{
		$this->readOnlyData = $data;
	}

	public function getReadOnlyData(): array
	{
		return $this->readOnlyData;
	}

	public function pullReadOnlyData()
	{
		$data = $this->readOnlyData;
		$this->readOnlyData = [];

		return $data;
	}

	public function pullProperties(): array
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::pullProperties',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		$result = parent::pullProperties();

		/** @var CBPActivity $activity */
		foreach ($this->arActivities as $activity)
		{
			foreach ($activity->pullProperties() as $activityId => $props)
			{
				$result[$activityId] = $props;

				$debugSessionService?->addTrace(
					TraceType::Log,
					'CBPCompositeActivity::pullProperties',
					Loc::getMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS_CHILD') ?? '',
					[
						'child_activity' => $activity->getName(),
						'activity_id' => $activityId,
						'properties_count' => count($props),
					],
				);
			}
		}

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::pullProperties',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_PULL_PROPS_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'total_activities' => count($result),
			],
		);

		return $result;
	}

	protected function reInitialize()
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::reInitialize',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_REINIT', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		parent::ReInitialize();

		/** @var CBPActivity $activity */
		foreach ($this->arActivities as $activity)
		{
			$activity->ReInitialize();

			$debugSessionService?->addTrace(
				TraceType::Log,
				'CBPCompositeActivity::reInitialize',
				Loc::getMessage('BPCGCA_DEBUG_TRACE_REINIT_CHILD') ?? '',
				[
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);
		}

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::reInitialize',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_REINIT_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'processed_count' => count($this->arActivities),
			],
		);
	}

	public function collectNestedActivities()
	{
		return $this->arActivities;
	}

	public function fixUpParentChildRelationship(CBPActivity $nestedActivity)
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::fixUpParentChildRelationship',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_FIX_RELATION') ?? '',
			[
				'parent_activity' => $this->getName(),
				'parent_type' => $this->getType(),
				'child_activity' => $nestedActivity->getName(),
				'child_type' => $nestedActivity->getType(),
				'current_children_count' => count($this->arActivities ?? []),
			],
		);

		parent::FixUpParentChildRelationship($nestedActivity);

		if (!is_array($this->arActivities))
			$this->arActivities = [];

		$this->arActivities[] = $nestedActivity;

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::fixUpParentChildRelationship',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_FIX_RELATION_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'child_activity' => $nestedActivity->getName(),
				'new_children_count' => count($this->arActivities),
			],
		);
	}

	protected function clearNestedActivities()
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$currentCount = count($this->arActivities);

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::clearNestedActivities',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_CLEAR') ?? '',
			[
				'parent_activity' => $this->getName(),
				'activities_to_clear' => $currentCount,
			],
		);

		$this->arActivities = [];

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::clearNestedActivities',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_CLEAR_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'cleared_count' => $currentCount,
			],
		);
	}

	public function initialize()
	{
		$debugSessionService = $this->workflow->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::initialize',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_INIT', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'workflow_instance_id' => $this->getWorkflowInstanceId(),
				'workflow_status_id' => $this->getWorkflowStatus(),
				'workflow_template_id' => $this->getWorkflowTemplateId(),
				'nested_activities_count' => count($this->arActivities),
				'properties' => $this->arProperties,
				'property_types' => $this->arPropertiesTypes,
				'read_only_data' => $this->readOnlyData,
			],
		);

		try
		{
			foreach ($this->arActivities as $activity)
			{
				$debugSessionService?->addTrace(
					TraceType::Log,
					'CBPCompositeActivity::initialize',
					Loc::getMessage('BPCGCA_DEBUG_TRACE_INIT_CHILD', ['#TITLE#' => $activity->getTitle()]) ?? '',
					[
						'parent_activity' => $this->getName(),
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
						'child_title' => $activity->getTitle(),
					],
				);

				$this->workflow->initializeActivity($activity);

				$debugSessionService?->addTrace(
					TraceType::Log,
					'CBPCompositeActivity::initialize',
					Loc::getMessage('BPCGCA_DEBUG_TRACE_INIT_CHILD_DONE') ?? '',
					[
						'child_activity' => $activity->getName(),
						'child_type' => $activity->getType(),
						'workflow_instance_id' => $activity->getWorkflowInstanceId(),
						'workflow_status_id' => $activity->getWorkflowStatus(),
					],
				);
			}

			$debugSessionService?->addTrace(
				TraceType::Log,
				'CBPCompositeActivity::initialize',
				Loc::getMessage('BPCGCA_DEBUG_TRACE_INIT_DONE') ?? '',
				[
					'parent_activity' => $this->getName(),
					'initialized_count' => count($this->arActivities),
				],
			);
		}
		catch (Throwable $exception)
		{
			$debugSessionService?->addTrace(
				TraceType::Error,
				'CBPCompositeActivity::initialize::exception',
				Loc::getMessage('BPCGCA_DEBUG_TRACE_INIT_ERROR', ['#MESSAGE#' => $exception->getMessage()]) ?? '',
				[
					'parent_activity' => $this->getName(),
					'exception_class' => $exception::class,
					'exception_message' => $exception->getMessage(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
					'code' => $exception->getCode(),
					'trace' => $exception->getTraceAsString(),
				],
			);

			throw $exception;
		}
	}

	public function finalize()
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::finalize',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_FINALIZE', ['#NAME#' => $this->getName()]) ?? '',
			[
				'activity_name' => $this->getName(),
				'activity_type' => $this->getType(),
				'nested_activities_count' => count($this->arActivities),
			],
		);

		foreach ($this->arActivities as $activity)
		{
			$debugSessionService?->addTrace(
				TraceType::Log,
				'CBPCompositeActivity::finalize',
				Loc::getMessage('BPCGCA_DEBUG_TRACE_FINALIZE_CHILD') ?? '',
				[
					'parent_activity' => $this->getName(),
					'child_activity' => $activity->getName(),
					'child_type' => $activity->getType(),
				],
			);

			$this->workflow->finalizeActivity($activity);
		}

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::finalize',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_FINALIZE_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'finalized_count' => count($this->arActivities),
			],
		);
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		return parent::ValidateProperties($arTestProperties, $user);
	}
}
