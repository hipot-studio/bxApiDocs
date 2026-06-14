<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPForEachActivity extends CBPCompositeActivity implements IBPActivityEventListener
{
	private $values;
	private $valuesKeys;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'Variable' => null,
			'Object' => null,

			//return
			'Key' => null,
			'Value' => null,
		];
	}

	public function executeWithPayload(\Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload $payload)
	{
		if (($this->getRootActivity() instanceof CBPNodeWorkflowActivity) && $payload->getInputPort() === 0)
		{
			$this->clearResults();
		}

		if ($this->TryNextIteration())
		{
			return CBPActivityExecutionStatus::Executing;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function cancel()
	{
		$activity = $this->arActivities[0] ?? null;
		if ($activity && $activity->executionStatus === CBPActivityExecutionStatus::Executing)
		{
			$this->workflow->cancelActivity($activity);

			return CBPActivityExecutionStatus::Canceling;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function onEvent(CBPActivity $sender, $arEventParameters = [])
	{
		$sender->RemoveStatusChangeHandler(self::ClosedEvent, $this);

		if (!$this->TryNextIteration())
		{
			$this->workflow->CloseActivity($this);
		}
	}

	private function TryNextIteration()
	{
		$this->outputPortId = 1;

		if (
			($this->executionStatus === CBPActivityExecutionStatus::Canceling)
			|| ($this->executionStatus === CBPActivityExecutionStatus::Faulting)
		)
		{
			return false;
		}

		if ($this->values === null)
		{
			$this->values = [];
			if ($this->Object === null)
			{
				$object = 'Variable';
				$this->Object = 'Variable';
			}
			else
			{
				$object = $this->Object;
			}
			$field = $this->Variable;

			[$property, $value] = $this->getRuntimeProperty($object, $field, $this);

			if ($value)
			{
				$this->values = (array)$value;
				$this->valuesKeys = array_keys($this->values);
				if ($property && isset($property['Type']))
				{
					$property['Multiple'] = false;
					$this->setPropertiesTypes(['Value' => $property]);
				}
			}
		}

		if (!count($this->values))
		{
			return false;
		}

		$this->Key = array_shift($this->valuesKeys);
		$this->Value = array_shift($this->values);

		if (count($this->arActivities) > 0)
		{
			/** @var CBPActivity $activity */
			$activity = $this->arActivities[0];
			$activity->ReInitialize();
			$activity->AddStatusChangeHandler(self::ClosedEvent, $this);
			$this->workflow->ExecuteActivity($activity);

			return true;
		}

		$this->outputPortId = 0;

		return false;
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		if (!($this->getRootActivity() instanceof CBPNodeWorkflowActivity))
		{
			$this->clearResults();
		}
	}

	protected function clearResults(): void
	{
		$this->values = null;
		$this->Key = null;
		$this->Value = null;
	}

	public static function GetPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = ""
	)
	{
		$arWorkflowVariables = is_array($arWorkflowVariables) ? $arWorkflowVariables : [];

		if (!is_array($arCurrentValues))
		{
			$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
			if (is_array($arCurrentActivity['Properties']))
			{
				$arCurrentValues = [
					'variable' => $arCurrentActivity['Properties']['Variable'] ?? null,
					'object' => $arCurrentActivity['Properties']['Object'] ?? null,
				];
			}
		}

		if (!is_array($arCurrentValues))
		{
			$arCurrentValues = [
				'variable' => null,
				'object' => null,
			];
		}

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(
			__FILE__,
			[
				'documentType' => $documentType,
				'activityName' => $activityName,
				'workflowTemplate' => $arWorkflowTemplate,
				'workflowParameters' => $arWorkflowParameters,
				'currentValues' => $arCurrentValues,
				'formName' => $formName,
			]
		);

		$dialog->setRuntimeData([
			"arCurrentValues" => $arCurrentValues,
			'workflowVariables' => $arWorkflowVariables,
		]);

		return $dialog;
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$arErrors,
		$arWorkflowConstants
	)
	{
		$arWorkflowVariables = is_array($arWorkflowVariables) ? $arWorkflowVariables : [];

		$properties = [
			'Variable' => null,
			'Object' => null,
		];

		$variableValue = $arCurrentValues['variable'] ?? '';
		$objectValue = $arCurrentValues['object'] ?? 'Variable';
		$wfFields = [
			\Bitrix\Bizproc\Workflow\Template\SourceType::Parameter => $arWorkflowParameters,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Variable => $arWorkflowVariables,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Constant => $arWorkflowConstants,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Activity => $arWorkflowTemplate,
		];

		if (static::existField($variableValue, $objectValue, $documentType, $wfFields))
		{
			$properties = [
				'Variable' => $variableValue,
				'Object' => $objectValue,
			];
		}

		$user = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$arErrors = self::validateProperties($properties, $user);

		if (count($arErrors) > 0)
		{
			return false;
		}

		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity['Properties'] = $properties;

		return true;
	}

	private static function existField(string $field, string $object, array $documentType, array $wfFields = []): bool
	{
		if (!$field)
		{
			return false;
		}

		if (
			$object === \Bitrix\Bizproc\Workflow\Template\SourceType::Parameter
			|| $object === \Bitrix\Bizproc\Workflow\Template\SourceType::Variable
			|| $object === \Bitrix\Bizproc\Workflow\Template\SourceType::Constant
		)
		{
			return array_key_exists($field, $wfFields[$object]);
		}

		if ($object === \Bitrix\Bizproc\Workflow\Template\SourceType::GlobalVariable)
		{
			return (bool)\Bitrix\Bizproc\Workflow\Type\GlobalVar::getVisibleById($field, $documentType);
		}
		if ($object === \Bitrix\Bizproc\Workflow\Template\SourceType::GlobalConstant)
		{
			return (bool)\Bitrix\Bizproc\Workflow\Type\GlobalConst::getVisibleById($field, $documentType);
		}
		if ($object === \Bitrix\Bizproc\Workflow\Template\SourceType::DocumentField)
		{
			$documentService = CBPRuntime::getRuntime()->getDocumentService();
			$documentFields = $documentService->GetDocumentFields($documentType);

			return array_key_exists($field, $documentFields);
		}

		if (CBPActivity::findActivityInTemplate(
			$wfFields[\Bitrix\Bizproc\Workflow\Template\SourceType::Activity],
			$object
		))
		{
			return true;
		}

		return false;
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];
		if (empty($arTestProperties['Variable']))
		{
			$errors[] = [
				'code' => 'emptyVariable',
				'message' => GetMessage('BPFEA_NO_SOURCE'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	public function collectUsages()
	{
		$usages = parent::collectUsages();
		if (!empty($this->arProperties['Variable']))
		{
			$field = $this->arProperties['Variable'];
			$object = $this->arProperties['Object'] ?? 'Variable';

			$usages[] = $this->getObjectSourceType($object, $field);
		}

		return $usages;
	}
}