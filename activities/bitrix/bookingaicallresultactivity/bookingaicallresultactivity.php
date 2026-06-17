<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class CBPBookingAiCallResultActivity extends CBPActivity
{
	private const SUCCESS_RESULT = 'Y';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'BookingMessageId' => null,
			'CallResult' => null,
		];

		$this->setPropertiesTypes([
			'BookingMessageId' => ['Type' => FieldType::INT],
			'CallResult' => ['Type' => FieldType::STRING],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'BookingMessageId' => [
				'Name' => Loc::getMessage('BOOKING_AICR_MAP_BOOKING_MESSAGE_ID'),
				'FieldName' => 'booking_message_id',
				'Type' => FieldType::INT,
				'Required' => true,
			],
			'CallResult' => [
				'Name' => Loc::getMessage('BOOKING_AICR_MAP_CALL_RESULT'),
				'FieldName' => 'call_result',
				'Type' => FieldType::STRING,
				'Required' => true,
			],
		];
	}

	public function execute()
	{
		if (!Loader::includeModule('booking'))
		{
			$this->trackError(Loc::getMessage('BOOKING_AICR_INCLUDE_MODULE'));

			return CBPActivityExecutionStatus::Closed;
		}

		$bookingMessageId = (int)$this->BookingMessageId;
		if ($bookingMessageId <= 0)
		{
			$this->trackError(Loc::getMessage('BOOKING_AICR_ERROR_BOOKING_MESSAGE_ID'));

			return CBPActivityExecutionStatus::Closed;
		}

		$sender = Container::getAiCallMessageSender();

		if ((string)$this->CallResult === self::SUCCESS_RESULT)
		{
			$sender->markSuccess($bookingMessageId);
			$this->WriteToTrackingService(Loc::getMessage('BOOKING_AICR_TRACK_SUCCESS'));
		}
		else
		{
			$sender->markFailed($bookingMessageId);
			$this->WriteToTrackingService(Loc::getMessage('BOOKING_AICR_TRACK_FAILED'));
		}

		return CBPActivityExecutionStatus::Closed;
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
					'message' => Loc::getMessage('BOOKING_AICR_ERROR_REQUIRED_' . mb_strtoupper($id)),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}
}
