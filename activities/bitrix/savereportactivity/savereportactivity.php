<?php

declare(strict_types=1);

use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Public\Command\Report\UpsertAiReportCommand;
use Bitrix\Timeman\V2\Public\Command\Report\UpsertCommand;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPSaveReportActivity extends CBPActivity
{
	private const PROP_USER_ID = 'UserId';
	private const PROP_REPORT_TEXT = 'ReportText';
	private const PROP_REPORT_TYPE = 'ReportType';
	private const PROP_RECORD_ID = 'RecordId';
	private const PROP_CONVERT_MARKDOWN = 'ConvertMarkdown';
	private const RETURN_SAVED = 'Saved';

	/**
	 * @throws LoaderException
	 */
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PROP_USER_ID => null,
			self::PROP_REPORT_TYPE => Loader::includeModule('timeman')
				? RecordReportType::REPORT
				: null,
			self::PROP_REPORT_TEXT => null,
			self::PROP_RECORD_ID => null,
			self::PROP_CONVERT_MARKDOWN => true,
			self::RETURN_SAVED => false,
		];

		$this->setPropertiesTypes([
			self::PROP_USER_ID => [
				'Type' => FieldType::USER,
			],
			self::PROP_REPORT_TEXT => [
				'Type' => FieldType::TEXT,
			],
			self::PROP_REPORT_TYPE => [
				'Type' => FieldType::SELECT,
			],
			self::PROP_RECORD_ID => [
				'Type' => FieldType::INT,
			],
			self::PROP_CONVERT_MARKDOWN => [
				'Type' => FieldType::BOOL,
			],
			self::RETURN_SAVED => [
				'Type' => FieldType::BOOL,
			],
		]);
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		return [self::PROP_RECORD_ID, self::PROP_CONVERT_MARKDOWN];
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	private static function isAiReportAvailable(): bool
	{
		$serviceLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();
		$interface = \Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class;
		if (!$serviceLocator->has($interface))
		{
			return true;
		}

		return $serviceLocator->get($interface)->isAvailable();
	}

	/**
	 * @throws LoaderException
	 */
	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		if (!Loader::includeModule('timeman'))
		{
			return [];
		}

		return [
			self::PROP_USER_ID => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_USER_ID'),
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => false,
			],
			self::PROP_REPORT_TEXT => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_REPORT_TEXT'),
				'FieldName' => 'report_text',
				'Type' => FieldType::TEXT,
				'Required' => true,
				'Multiple' => false,
			],
			self::PROP_REPORT_TYPE => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_REPORT_TYPE'),
				'FieldName' => 'report_type',
				'Type' => FieldType::SELECT,
				'Options' => array_filter(
					[
						RecordReportType::REPORT => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_REPORT_TYPE_RECORD'),
						RecordReportType::AI_REPORT => self::isAiReportAvailable()
							? Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_REPORT_TYPE_AI')
							: null,
						RecordReportType::ROBOT_REPORT => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_REPORT_TYPE_ROBOT'),
					],
					static fn($label) => $label !== null,
				),
				'Required' => true,
				'Multiple' => false,
				'AllowSelection' => false,
				'Default' => RecordReportType::REPORT,
			],
			self::PROP_RECORD_ID => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_RECORD_ID'),
				'FieldName' => 'record_id',
				'Type' => FieldType::INT,
				'Required' => false,
				'Default' => 0,
			],
			self::PROP_CONVERT_MARKDOWN => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_CONVERT_MARKDOWN'),
				'FieldName' => 'convert_markdown',
				'Type' => FieldType::BOOL,
				'Default' => true,
			],
		];
	}

	/**
	 * @throws LoaderException
	 */
	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors,
	): bool
	{
		$errors = [];

		if (!Loader::includeModule('timeman'))
		{
			$errors[] = [
				'code' => 'ModuleNotLoaded',
				'message' => Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_MODULE_NOT_LOADED'),
			];

			return false;
		}

		$properties = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		foreach (static::getPropertiesMap($documentType) as $id => $property)
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

		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($arWorkflowTemplate, $activityName);
		foreach (self::getAiPassthroughSettingNames($documentType) as $propertyId)
		{
			if (isset($currentActivity['Properties'][$propertyId]))
			{
				$properties[$propertyId] = $currentActivity['Properties'][$propertyId];
			}
		}
		$currentActivity['Properties'] = $properties;

		return true;
	}

	/**
	 * @throws LoaderException
	 */
	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (!static::checkAdminPermissions())
		{
			$errors[] = [
				'code' => 'AccessDenied',
				'parameter' => 'Admin',
				'message' => Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_ACCESS_DENIED'),
			];

			return array_merge($errors, parent::validateProperties($arTestProperties, $user));
		}

		foreach (self::getPropertiesMap([]) as $id => $property)
		{
			if (
				CBPHelper::getBool($property['Required'] ?? null)
				&& CBPHelper::isEmptyValue($arTestProperties[$id] ?? null)
			)
			{
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => $id,
					'message' => Loc::getMessage('TIMEMAN_SAVE_REPORT_PROP_' . mb_strtoupper($id) . '_EMPTY'),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	/**
	 * @throws LoaderException
	 * @throws CommandValidationException
	 * @throws CommandException
	 */
	public function execute(): int
	{
		$this->{self::RETURN_SAVED} = false;
		if (!Loader::includeModule('timeman'))
		{
			$this->trackError(Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_MODULE_NOT_LOADED'));

			return CBPActivityExecutionStatus::Closed;
		}

		$recordContext = $this->resolveRecordContext();
		$userId = $recordContext === null ? $this->resolveUserId() : (int)$recordContext['userId'];

		if ($userId === null || $userId <= 0)
		{
			$this->trackError(Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_USER_ID_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$record = $recordContext === null
			? Container::getInstance()->getRecordRepository()->getCurrentRecord($userId, false, false)
			: (new RecordProvider())->getById((int)$recordContext['recordId'], false, false)
		;

		if ($record === null || $record->userId !== $userId)
		{
			$this->trackError(Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_RECORD_NOT_FOUND'));

			return CBPActivityExecutionStatus::Closed;
		}
		$reportText = (string)$this->{self::PROP_REPORT_TEXT};

		if (CBPHelper::isEmptyValue($reportText))
		{
			$this->trackError(Loc::getMessage('TIMEMAN_SAVE_REPORT_ERROR_REPORT_TEXT_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (CBPHelper::getBool($this->{self::PROP_CONVERT_MARKDOWN}))
		{
			$reportText = $this->convertMarkdownToBbCode($reportText);
		}

		$reportType = (string)($this->{self::PROP_REPORT_TYPE} ?? RecordReportType::REPORT);
		$command = $reportType === RecordReportType::AI_REPORT
			? new UpsertAiReportCommand($record->getId(), $userId, $reportText)
			: new UpsertCommand($record->getId(), $userId, $reportText, RecordReportType::normalize($reportType));

		$result = $command->run();

		if (!$result->isSuccess())
		{
			$this->trackError(implode('; ', $result->getErrorMessages()));
		}
		else
		{
			$this->{self::RETURN_SAVED} = true;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function resolveRecordContext(): ?array
	{
		$recordId = (int)$this->{self::PROP_RECORD_ID};
		if ($recordId <= 0)
		{
			return null;
		}

		$userId = $this->resolveUserId();

		return [
			'userId' => (int)$userId,
			'recordId' => $recordId,
		];
	}

	private function resolveUserId(): ?int
	{
		return CBPHelper::extractFirstUser($this->{self::PROP_USER_ID}, $this->getDocumentId());
	}

	private static function checkAdminPermissions(): bool
	{
		return (new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	private function convertMarkdownToBbCode(string $text): string
	{
		if (
			!Loader::includeModule('ai')
			|| !class_exists(MarkdownToBBCodeTranslationService::class)
		)
		{
			return $text;
		}

		return ServiceLocator::getInstance()
			->get(MarkdownToBBCodeTranslationService::class)
			->convert($text)
		;
	}
}
