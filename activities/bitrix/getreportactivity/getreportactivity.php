<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPGetReportActivity extends CBPActivity
{
	private const PROP_USER_ID = 'UserId';
	private const PROP_REPORT_TYPE = 'ReportType';
	private const PROP_REPORT_TEXT = 'ReportText';
	private const PROP_IS_FOUND = 'IsFound';

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PROP_USER_ID => null,
			self::PROP_REPORT_TYPE => Loader::includeModule('timeman')
				? RecordReportType::REPORT
				: null,
			self::PROP_REPORT_TEXT  => null,
			self::PROP_IS_FOUND   => null,
		];

		$this->setPropertiesTypes([
			self::PROP_USER_ID => [
				'Type' => FieldType::USER,
				'Multiple' => false,
			],
			self::PROP_REPORT_TYPE => [
				'Type' => FieldType::SELECT,
				'Multiple' => false,
			],
			self::PROP_REPORT_TEXT => [
				'Type' => FieldType::TEXT,
				'Multiple' => false,
			],
			self::PROP_IS_FOUND => [
				'Type' => FieldType::BOOL,
				'Multiple' => false,
			],
		]);
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
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

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		if (!Loader::includeModule('timeman'))
		{
			return [];
		}

		return [
			self::PROP_USER_ID => [
				'Name' => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_USER_ID'),
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => false,
			],
			self::PROP_REPORT_TYPE => [
				'Name' => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_REPORT_TYPE'),
				'FieldName' => 'report_type',
				'Type' => FieldType::SELECT,
				'Options' => [
					RecordReportType::REPORT => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_REPORT_TYPE_RECORD'),
					RecordReportType::AI_REPORT => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_REPORT_TYPE_AI'),
					RecordReportType::ROBOT_REPORT => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_REPORT_TYPE_ROBOT'),
				],
				'Required' => true,
				'Multiple' => false,
				'AllowSelection' => false,
				'Default' => RecordReportType::REPORT,
			],
		];
	}

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
				'message' => Loc::getMessage('TIMEMAN_GET_REPORT_ERROR_MODULE_NOT_LOADED'),
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
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (!static::checkAdminPermissions())
		{
			$errors[] = [
				'code' => 'AccessDenied',
				'parameter' => 'Admin',
				'message' => Loc::getMessage('TIMEMAN_GET_REPORT_ERROR_ACCESS_DENIED'),
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
					'message' => Loc::getMessage('TIMEMAN_GET_REPORT_PROP_' . mb_strtoupper($id) . '_EMPTY'),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function execute(): int
	{
		if (!Loader::includeModule('timeman'))
		{
			$this->trackError(Loc::getMessage('TIMEMAN_GET_REPORT_ERROR_MODULE_NOT_LOADED'));

			return CBPActivityExecutionStatus::Closed;
		}

		$userId = $this->resolveUserId();

		if ($userId === null || $userId <= 0)
		{
			$this->trackError(Loc::getMessage('TIMEMAN_GET_REPORT_ERROR_USER_ID_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$record = Container::getInstance()
			->getRecordRepository()
			->getCurrentRecord($userId, false, false)
		;

		if ($record === null || $record->userId !== $userId)
		{
			$this->{self::PROP_REPORT_TEXT} = '';
			$this->{self::PROP_IS_FOUND} = false;

			return CBPActivityExecutionStatus::Closed;
		}

		$reportType = (string)($this->{self::PROP_REPORT_TYPE} ?? RecordReportType::REPORT);
		$report = Container::getInstance()
			->getReportRepository()
			->getByRecordIdAndType($record->getId(), $reportType)
		;
		$this->{self::PROP_REPORT_TEXT} = $report?->report ?? '';
		$this->{self::PROP_IS_FOUND} = $report !== null;

		return CBPActivityExecutionStatus::Closed;
	}

	private function resolveUserId(): ?int
	{
		return CBPHelper::extractFirstUser($this->{self::PROP_USER_ID}, $this->getDocumentId());
	}

	private static function checkAdminPermissions(): bool
	{
		return (new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}
}
