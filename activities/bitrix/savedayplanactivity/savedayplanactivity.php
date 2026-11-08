<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\AI\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\V2\Public\Command\Report\UpsertPlanCommand;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;

class CBPSaveDayPlanActivity extends BaseActivity
{
	private const PARAM_USER_ID = 'UserId';
	private const PARAM_RECORD_ID = 'RecordId';
	private const PARAM_PLAN_TEXT = 'PlanText';
	private const PARAM_PLAN_TYPE = 'PlanType';
	private const PARAM_CONVERT_MARKDOWN = 'ConvertMarkdown';
	private const RETURN_SAVED = 'Saved';

	protected static $requiredModules = ['timeman'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_USER_ID => null,
			self::PARAM_RECORD_ID => 0,
			self::PARAM_PLAN_TEXT => '',
			self::PARAM_PLAN_TYPE => Loader::includeModule('timeman') ? RecordReportType::ROBOT_DAY_PLAN : null,
			self::PARAM_CONVERT_MARKDOWN => true,
			self::RETURN_SAVED => false,
		];

		$this->setPropertiesTypes([
			self::PARAM_USER_ID => [
				'Type' => FieldType::USER,
			],
			self::PARAM_RECORD_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_PLAN_TEXT => [
				'Type' => FieldType::TEXT,
			],
			self::PARAM_PLAN_TYPE => [
				'Type' => FieldType::SELECT,
			],
			self::PARAM_CONVERT_MARKDOWN => [
				'Type' => FieldType::BOOL,
			],
			self::RETURN_SAVED => [
				'Type' => FieldType::BOOL,
			],
		]);
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		return [self::PARAM_RECORD_ID, self::PARAM_CONVERT_MARKDOWN];
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$this->setSaved(false);

		$userId = $this->extractUserId();
		if ($userId <= 0)
		{
			$errors->setError(
				new Error(Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_ERROR_EMPTY_USER') ?? '')
			);

			return $errors;
		}
		$recordId = (int)$this->{self::PARAM_RECORD_ID};
		$planType = (string)$this->{self::PARAM_PLAN_TYPE};
		$planText = $this->preparePlanText(CBPHelper::stringify($this->{self::PARAM_PLAN_TEXT}));

		$result = (new UpsertPlanCommand(
			userId: $userId,
			planText: $planText,
			planType: $planType,
			recordId: $recordId,
		))->run();

		foreach ($result->getErrors() as $error)
		{
			$errors->setError($error);
		}
		$this->setSaved($errors->isEmpty());

		return $errors;
	}

	final protected function setSaved(bool $saved): void
	{
		$this->setProperty(self::RETURN_SAVED, $saved);
	}

	private function extractUserId(): int
	{
		$userId = \CBPHelper::extractFirstUser($this->{self::PARAM_USER_ID}, $this->getDocumentId());

		return (int)$userId;
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

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return [
			self::PARAM_USER_ID => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_USER_PROPERTY') ?? '',
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_RECORD_ID => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_RECORD_ID_PROPERTY') ?? '',
				'FieldName' => 'record_id',
				'Type' => FieldType::INT,
				'Required' => false,
				'Default' => 0,
			],
			self::PARAM_PLAN_TEXT => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TEXT_PROPERTY') ?? '',
				'FieldName' => 'plan_text',
				'Type' => FieldType::TEXT,
				'Required' => true,
			],
			self::PARAM_PLAN_TYPE => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TYPE_PROPERTY') ?? '',
				'FieldName' => 'plan_type',
				'Type' => FieldType::SELECT,
				'Options' => array_filter(
					[
						RecordReportType::AI_DAY_PLAN => self::isAiReportAvailable()
							? Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TYPE_AI')
							: null,
						RecordReportType::ROBOT_DAY_PLAN => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TYPE_ROBOT'),
					],
					static fn($label) => $label !== null,
				),
				'Default' => RecordReportType::ROBOT_DAY_PLAN,
			],
			self::PARAM_CONVERT_MARKDOWN => [
				'Name' => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_CONVERT_MARKDOWN_PROPERTY') ?? '',
				'FieldName' => 'convert_markdown',
				'Type' => FieldType::BOOL,
				'Default' => true,
			],
		];
	}

	private function preparePlanText(string $text): string
	{
		if (!CBPHelper::getBool($this->{self::PARAM_CONVERT_MARKDOWN}))
		{
			return $text;
		}

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
