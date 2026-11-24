<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
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

	/**
	 * Whether the node can run at all. The sender is not compared with anybody: it is commonly an expression,
	 * a group or a department, and whom it names is settled when the process runs.
	 */
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

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	/**
	 * Whether the author may write these settings: an ordinary employee sends notifications on his own
	 * behalf only. Asked on the paths where the node settings come from the form, and deliberately apart
	 * from ValidateProperties(), which publishing and importing of somebody else's template also call.
	 *
	 * @return array errors in the ValidateProperties() format
	 */
	public static function validateAuthoredProperties(array $properties, \CBPWorkflowTemplateUser $user): array
	{
		$authorId = (int)$user->getId();
		if (!self::namesForeignEmployee($properties['MessageUserFrom'] ?? null, $authorId))
		{
			return [];
		}

		if ($user->isAdmin() || (new TemplateAccessService())->canConfigureRights($authorId))
		{
			return [];
		}

		return [[
			'code' => 'ForeignSender',
			'parameter' => 'MessageUserFrom',
			'message' => Loc::getMessage('BPIMNA_FOREIGN_FROM'),
		]];
	}

	/**
	 * Whether the value names somebody except the author. Every element is inspected, not only a single one:
	 * on execution CBPHelper::ExtractUsers($value, $documentId, true) takes the first element that resolves
	 * and never looks at the rest, so a neighbour in the list does not make the sender undetermined.
	 */
	private static function namesForeignEmployee(mixed $value, int $authorId): bool
	{
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			$employeeId = self::extractEmployeeId($item);
			if ($employeeId !== null && $employeeId !== $authorId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The id of the employee a single element names by itself, or null when the element names a group, a
	 * department, the document author or an expression: whom those name is settled when the process runs.
	 * A bare number is a group code in bizproc (CBPHelper::convertToExtendedGroups()), not an employee.
	 *
	 * The element is read the way its consumer reads it: `user_<id>` keeps whatever is appended to the
	 * number, because intval() drops that tail on execution as well. Prefixes are read regardless of their
	 * case although the consumer compares them case-sensitively — of the two sides of that difference this
	 * one refuses more.
	 */
	private static function extractEmployeeId(mixed $item): ?int
	{
		if (!is_scalar($item))
		{
			return null;
		}

		$item = (string)$item;
		if (stripos($item, 'user_') === 0)
		{
			$employeeId = (int)mb_substr($item, mb_strlen('user_'));

			return $employeeId > 0 ? $employeeId : null;
		}

		if (\CBPActivity::isExpression($item))
		{
			return null;
		}

		if (preg_match('/^group_[is]?u(\d+)$/i', $item, $matches))
		{
			return (int)$matches[1];
		}

		return null;
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
