<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

class CBPIMNotifyActivity extends \CBPActivity implements IBPConfigurableActivity
{
	// value of IM_NOTIFY_PERSONAL (im module constant is not available at edit time)
	private const PERSONAL_NOTIFY_TYPE = 2;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			"Title" => "",
			"MessageUserFrom" => "",
			"MessageUserTo" => "",
			"MessageSite" => "",
			"MessageOut" => "",
			"MessageType" => "",
		];
	}

	public function Execute()
	{
		if (!\Bitrix\Main\Loader::includeModule("im"))
		{
			return \CBPActivityExecutionStatus::Closed;
		}

		$rootActivity = $this->GetRootActivity();
		$documentId = $rootActivity->GetDocumentId();

		$arMessageUserFrom = \CBPHelper::ExtractUsers($this->MessageUserFrom, $documentId, true);
		$arMessageUserTo = \CBPHelper::ExtractUsers($this->MessageUserTo, $documentId, false);

		$notifyMessage = CBPHelper::stringify($this->MessageSite);
		$notifyMessageOut = CBPHelper::stringify($this->MessageOut);

		$arMessageFields = [
			'FROM_USER_ID' => (int)$this->MessageType === IM_NOTIFY_SYSTEM ? 0 : $arMessageUserFrom,
			'NOTIFY_TYPE' => (int)$this->MessageType,
			'NOTIFY_MESSAGE' => $notifyMessage,
			'NOTIFY_MESSAGE_OUT' => $notifyMessageOut,
			'NOTIFY_MODULE' => 'bizproc',
			'NOTIFY_EVENT' => 'activity',
			'PUSH_MESSAGE' => $this->getPushText($notifyMessage),
		];

		$ar = [];
		foreach ($arMessageUserTo as $userTo)
		{
			if (in_array($userTo, $ar))
			{
				continue;
			}

			$ar[] = $userTo;
			$arMessageFields["TO_USER_ID"] = $userTo;
			\CIMNotify::Add($arMessageFields);
		}

		return \CBPActivityExecutionStatus::Closed;
	}

	private function getPushText(string $htmlMessage): string
	{
		$text = mb_substr(\CTextParser::clearAllTags($htmlMessage), 0, 200);
		if (mb_strlen($text) === 200)
		{
			$text .= '...';
		}

		return $text;
	}

	public static function ValidateProperties($arTestProperties = [], \CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = [];

		if (empty($arTestProperties['MessageUserFrom']))
		{
			$arErrors[] = [
				"code" => "NotExist",
				"parameter" => "MessageUserFrom",
				"message" => Loc::getMessage("BPIMNA_EMPTY_FROM"),
			];
		}
		if (empty($arTestProperties['MessageUserTo']))
		{
			$arErrors[] = [
				"code" => "NotExist",
				"parameter" => "MessageUserTo",
				"message" => Loc::getMessage("BPIMNA_EMPTY_TO"),
			];
		}
		if (empty($arTestProperties['MessageSite']))
		{
			$arErrors[] = [
				"code" => "NotExist",
				"parameter" => "MessageText",
				"message" => Loc::getMessage("BPIMNA_EMPTY_MESSAGE"),
			];
		}

		$from = array_key_exists("MessageUserFrom", $arTestProperties) ? $arTestProperties["MessageUserFrom"] : null;
		if ($user && $from !== $user->getBizprocId() && !$user->isAdmin())
		{
			$arErrors[] = [
				"code" => "NotExist",
				"parameter" => "MessageUserFrom",
				"message" => Loc::getMessage("BPIMNA_EMPTY_FROM"),
			];
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters,
		$arWorkflowVariables, $arCurrentValues = null, $formName = "")
	{
		$runtime = \CBPRuntime::GetRuntime();

		$arMap = [
			"MessageUserFrom" => "from_user_id",
			"MessageUserTo" => "to_user_id",
			"MessageSite" => "message_site",
			"MessageOut" => "message_out",
			"MessageType" => "message_type",
		];

		if (!is_array($arWorkflowParameters))
		{
			$arWorkflowParameters = [];
		}
		if (!is_array($arWorkflowVariables))
		{
			$arWorkflowVariables = [];
		}

		if (!is_array($arCurrentValues))
		{
			$arCurrentActivity = &\CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
			if (is_array($arCurrentActivity["Properties"]))
			{
				foreach ($arMap as $k => $v)
				{
					if (array_key_exists($k, $arCurrentActivity["Properties"]))
					{
						if ($k == "MessageUserFrom" || $k == "MessageUserTo")
						{
							$arCurrentValues[$arMap[$k]] = \CBPHelper::UsersArrayToString($arCurrentActivity["Properties"][$k],
								$arWorkflowTemplate, $documentType);
						}
						else
						{
							$arCurrentValues[$arMap[$k]] = $arCurrentActivity["Properties"][$k];
						}
					}
					else
					{
						if ($k == "MessageType")
						{
							$arCurrentValues[$arMap[$k]] = 2;
						}
						else
						{
							$arCurrentValues[$arMap[$k]] = "";
						}
					}
				}
			}
			else
			{
				foreach ($arMap as $k => $v)
				{
					$arCurrentValues[$arMap[$k]] = "";
				}
			}
		}

		return $runtime->ExecuteResourceFile(
			__FILE__,
			"properties_dialog.php",
			[
				"arCurrentValues" => $arCurrentValues,
				"formName" => $formName,
				'user' => new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser),
			]
		);
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate,
		&$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$errors)
	{
		$errors = [];
		$properties = [];
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if (!is_array($arCurrentValues))
		{
			$arCurrentValues = [];
		}

		if (array_key_exists('to_user_id', $arCurrentValues))
		{
			$arMap = [
				"from_user_id" => "MessageUserFrom",
				"to_user_id" => "MessageUserTo",
				"message_site" => "MessageSite",
				"message_out" => "MessageOut",
				"message_type" => "MessageType",
			];

			foreach ($arMap as $key => $value)
			{
				if ($key == "from_user_id" || $key == "to_user_id")
				{
					continue;
				}

				$properties[$value] = $arCurrentValues[$key];
			}

			if ($user->isAdmin())
			{
				$properties["MessageUserFrom"] = \CBPHelper::UsersStringToArray(
					$arCurrentValues["from_user_id"],
					$documentType,
					$errors
				);
				if ($errors)
				{
					return false;
				}
			}

			$properties["MessageUserTo"] = \CBPHelper::UsersStringToArray(
				$arCurrentValues["to_user_id"],
				$documentType,
				$errors
			);
			if ($errors)
			{
				return false;
			}
		}
		else
		{
			$propertiesMap = self::getPropertiesMap($documentType);
			$documentService = \CBPRuntime::getRuntime()->getDocumentService();
			$request = $arCurrentValues;

			foreach ($propertiesMap as $propertyName => $fieldProperties)
			{
				// sender of a non-admin is always replaced by the current user below
				if ($propertyName === 'MessageUserFrom' && !$user->isAdmin())
				{
					continue;
				}

				$fieldName = (string)$fieldProperties['FieldName'];
				$rawValue = $request[$fieldName] ?? null;

				if (($fieldProperties['Type'] ?? null) === FieldType::USER && is_array($rawValue))
				{
					$errors[] = [
						'code' => 'InvalidType',
						'parameter' => $propertyName,
						'message' => Loc::getMessage(
							$propertyName === 'MessageUserFrom' ? 'BPIMNA_EMPTY_FROM' : 'BPIMNA_EMPTY_TO',
						),
					];

					return false;
				}

				if (
					($rawValue === null || $rawValue === '')
					&& array_key_exists('Default', $fieldProperties)
				)
				{
					$request[$fieldName] = $fieldProperties['Default'];
				}

				$fieldReference = ['Field' => $fieldName];
				$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
				if ($field)
				{
					$fieldErrors = [];
					$properties[$propertyName] = $field->extractValue($fieldReference, $request, $fieldErrors);
					if ($fieldErrors)
					{
						$errors = array_merge($errors, $fieldErrors);
					}
				}
				else
				{
					$properties[$propertyName] = $documentService->getFieldInputValue(
						$documentType,
						$fieldProperties,
						$fieldReference,
						$request,
						$errors,
					);
				}
			}
			if ($errors)
			{
				return false;
			}
		}

		if (!$user->isAdmin())
		{
			$properties["MessageUserFrom"] = $user->getBizprocId();
		}

		$errors = self::ValidateProperties(
			$properties,
			new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser)
		);
		if ($errors)
		{
			return false;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$currentActivity["Properties"] = $properties;

		return true;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'MessageUserFrom' => [
				'Name' => Loc::getMessage('BPIMNA_FIELD_FROM'),
				'FieldName' => 'message_user_from',
				'Type' => FieldType::USER,
				'Required' => true,
			],
			'MessageUserTo' => [
				'Name' => Loc::getMessage('BPIMNA_FIELD_TO'),
				'FieldName' => 'message_user_to',
				'Type' => FieldType::USER,
				'Multiple' => true,
				'Required' => true,
			],
			'MessageSite' => [
				'Name' => Loc::getMessage('BPIMNA_FIELD_MESSAGE'),
				'FieldName' => 'message_site',
				'Type' => FieldType::TEXT,
				'Required' => true,
			],
			'MessageOut' => [
				'Name' => Loc::getMessage('BPIMNA_FIELD_MESSAGE_OUT'),
				'FieldName' => 'message_out',
				'Type' => FieldType::TEXT,
				'Required' => false,
			],
			'MessageType' => [
				'Name' => Loc::getMessage('BPIMNA_FIELD_NOTIFY_TYPE'),
				'FieldName' => 'message_type',
				'Type' => FieldType::INT,
				'Hidden' => true,
				'Default' => self::PERSONAL_NOTIFY_TYPE,
				'AllowSelection' => false,
			],
		];
	}
}
