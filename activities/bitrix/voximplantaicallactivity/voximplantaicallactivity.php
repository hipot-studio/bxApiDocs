<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\PhoneNumber\Parser as PhoneParser;
use Bitrix\AiAssistant\Request\Service\Authentication\RequestSignerService;

class CBPVoximplantAiCallActivity extends CBPActivity
	implements IBPEventActivity, IBPActivityExternalEventListener
{
	private $callId;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'Number' => null,
			'Prompt' => null,
			'PromptScenario' => '',
			'PromptContext' => '',
			'Mcp' => [],
			'Result' => null,
			'ResultText' => '',
			'ResultCode' => '',
		];

		$this->setPropertiesTypes([
			'Number' => [
				'Type' => FieldType::STRING,
			],
			'Prompt' => [
				'Type' => FieldType::TEXT,
			],
			'ResultText' => [
				'Type' => FieldType::STRING,
			],
			'ResultCode' => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'Number' => [
				'Name' => Loc::getMessage('BPVAICA_MAP_NUMBER'),
				'FieldName' => 'number',
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			'Prompt' => [
				'Name' => Loc::getMessage('BPVAICA_MAP_PROMPT'),
				'FieldName' => 'prompt',
				'Type' => FieldType::TEXT,
			],
		];
	}

	public function execute()
	{
		if (!$this->loadRequiredModules())
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$number = $this->Number;
		$prompt = $this->Prompt;

		if ($this->workflow->isDebug())
		{
			$map = static::getPropertiesMap($this->getDocumentType());
			$this->writeDebugInfo($this->getDebugInfo(
				['Number' => $number, 'Prompt' => $prompt],
				['Number' => $map['Number'], 'Prompt' => $map['Prompt']],
			));
		}

		if (CBPHelper::isEmptyValue($number))
		{
			$this->trackError(Loc::getMessage('BPVAICA_ERROR_NUMBER'));

			return CBPActivityExecutionStatus::Closed;
		}

		$triggerEventData = CBPDocument::PARAM_TRIGGER_EVENT_DATA;
		$context = $this->getRootActivity()->{$triggerEventData} ?? [];
		$this->PromptScenario = $context['PromptScenario'] ?? '';

		if (CBPHelper::isEmptyValue($this->PromptScenario))
		{
			$this->trackError(
				'AI call requires a prompt scenario, which is provided only inside the online-booking AI agent.'
				. ' Add this action there instead of a robot or business process.'
			);

			$this->Result = false;

			return CBPActivityExecutionStatus::Closed;
		}

		$this->PromptContext = $context['PromptContext'] ?? '';
		$this->Mcp = $context['Mcp'] ?? [];

		$mcpContext = $this->Mcp['Context'] ?? [];
		$mcpContext['workflowInstanceId'] = $this->getWorkflowInstanceId();

		$mcpUserId = isset($this->Mcp['UserId']) ? (int)$this->Mcp['UserId'] : 0;

		$crmEntityType = (string)($context['CrmEntityType'] ?? '');
		$crmEntityId = (int)($context['CrmEntityId'] ?? 0);

		// Resolve the responsible user before placing the call so the resulting statistic record
		// and CRM activity always have an owner. Mirror the regular outgoing-call flow that sets
		// USER_ID up front (vi_outgoing.php StartCall), instead of patching PORTAL_USER_ID later
		// inside CVoxImplantHistory::Add.
		$responsibleUserId = 0;
		if ($crmEntityType !== '' && $crmEntityId > 0)
		{
			$crmResponsibleId = (int)\CVoxImplantCrmHelper::getResponsible($crmEntityType, $crmEntityId);
			if ($crmResponsibleId > 0)
			{
				$responsibleUserId = $crmResponsibleId;
			}
		}
		if ($responsibleUserId <= 0 && $mcpUserId > 0)
		{
			$responsibleUserId = $mcpUserId;
		}

		$apiCallData = [
			'Number' => $this->Number,
			'Prompt' => $this->Prompt,
			'PromptScenario' => $this->PromptScenario,
			'PromptContext' => $this->PromptContext,
			'Mcp.ToolSetId' => $this->Mcp['ToolSetId'] ?? null,
			'Mcp.ToolContext' => $mcpContext,
			'AuthToken' => $mcpUserId ? (new RequestSignerService())->sign($mcpUserId) : '',
			// CRM bindings for the call activity.
			// CrmEntityType: string CCrmOwnerType name (e.g. 'DEAL', 'CONTACT'), CrmEntityId: int entity ID.
			// CrmBindings: array of ['OWNER_TYPE_ID' => int, 'OWNER_ID' => int] for multiple entity links
			// (e.g. deal + contact). Passed through to CRM activity creation via AddCallHistory.
			'CrmEntityType' => $crmEntityType,
			'CrmEntityId' => $crmEntityId,
			'CrmBindings' => $context['CrmBindings'] ?? [],
			'UserId' => $responsibleUserId,
		];

		$callResult = \CVoxImplantOutgoing::StartAiCall($apiCallData);

		if (!$callResult->isSuccess())
		{
			$this->WriteToTrackingService(
				implode('; ', $callResult->getErrorMessages()),
				0,
				CBPTrackingType::Error
			);

			$this->Result = false;
			return CBPActivityExecutionStatus::Closed;
		}

		$callData = $callResult->getData();
		$this->callId = $callData['CALL_ID'];

		$this->Subscribe($this);
		$this->WriteToTrackingService(Loc::getMessage('BPVAICA_TRACK_SUBSCR'));

		return CBPActivityExecutionStatus::Executing;
	}

	public function Cancel()
	{
		$this->Unsubscribe($this);

		return CBPActivityExecutionStatus::Closed;
	}

	public function Subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$schedulerService = $this->workflow->GetService('SchedulerService');
		$schedulerService->SubscribeOnEvent(
			$this->workflow->GetInstanceId(),
			$this->name,
			'voximplant',
			'OnAiCallResult',
			$this->callId
		);

		$this->workflow->AddEventHandler($this->name, $eventHandler);
	}

	public function Unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$schedulerService = $this->workflow->GetService('SchedulerService');
		$schedulerService->UnSubscribeOnEvent(
			$this->workflow->GetInstanceId(),
			$this->name,
			'voximplant',
			'OnAiCallResult',
			$this->callId
		);

		$this->workflow->RemoveEventHandler($this->name, $eventHandler);
	}

	public function OnExternalEvent($arEventParameters = [])
	{
		$parameters = $arEventParameters[1];

		if (!is_array($parameters))
		{
			return;
		}

		if ($this->callId != $arEventParameters[0])
		{
			return;
		}

		$this->Result = ($parameters['RESULT'] ? 'Y' : 'N');

		$this->ResultText = sprintf(
			'%s (%s)',
			Loc::getMessage($parameters['RESULT'] ? 'BPVAICA_RESULT_TRUE' : 'BPVAICA_RESULT_FALSE'),
			$parameters['CODE']
		);
		$this->ResultCode = $parameters['CODE'];

		$this->Unsubscribe($this);
		$this->workflow->CloseActivity($this);
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$currentValues = null,
		$formName = '',
		$popupWindow = null,
		$currentSiteId = null
	)
	{
		if (!Loader::includeModule('voximplant'))
		{
			return '';
		}

		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $currentValues,
			'formName' => $formName,
			'siteId' => $currentSiteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors
	)
	{
		$errors = [];
		$properties = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType);

		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$arCurrentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$errors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);

		if (!empty($errors))
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName(
			$workflowTemplate,
			$activityName,
		);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function validateProperties(
		$arTestProperties = [],
		CBPWorkflowTemplateUser $user = null
	)
	{
		$errors = [];

		foreach (static::getPropertiesMap([]) as $id => $property)
		{
			if (
				CBPHelper::getBool($property['Required'] ?? null)
				&& CBPHelper::isEmptyValue($arTestProperties[$id] ?? null)
			)
			{
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => $id,
					'message' => Loc::getMessage('BPVAICA_ERROR_NUMBER'),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	private function normalizePhoneNumber(string $number): ?string
	{
		$phone = PhoneParser::getInstance()->parse($number, 'RU');
		if (!$phone->isValid())
		{
			return null;
		}

		return $phone->getCountryCode() . $phone->getNationalNumber();
	}

	private function loadRequiredModules(): bool
	{
		$modules = [
			'voximplant' => Loc::getMessage('BPVAICA_INCLUDE_MODULE'),
			'aiassistant' => Loc::getMessage('BPVAICA_INCLUDE_MODULE_AIASSISTANT'),
		];

		foreach ($modules as $moduleId => $errorMessage)
		{
			if (!Loader::includeModule($moduleId))
			{
				$this->trackError($errorMessage);

				return false;
			}
		}

		return true;
	}
}
