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
	private const PARAM_PLAN_TEXT = 'PlanText';
	private const PARAM_PLAN_TYPE = 'PlanType';

	protected static $requiredModules = ['timeman'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_USER_ID => null,
			self::PARAM_PLAN_TEXT => '',
			self::PARAM_PLAN_TYPE => Loader::includeModule('timeman') ? RecordReportType::ROBOT_DAY_PLAN : null,
		];

		$this->setPropertiesTypes([
			self::PARAM_USER_ID => [
				'Type' => FieldType::USER,
			],
			self::PARAM_PLAN_TEXT => [
				'Type' => FieldType::TEXT,
			],
			self::PARAM_PLAN_TYPE => [
				'Type' => FieldType::SELECT,
			],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();

		$userId = $this->extractUserId();
		if ($userId <= 0)
		{
			$errors->setError(
				new Error(Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_ERROR_EMPTY_USER') ?? '')
			);

			return $errors;
		}

		$planText = $this->convertMarkdownToBbCode(CBPHelper::stringify($this->{self::PARAM_PLAN_TEXT}));

		$result = (new UpsertPlanCommand(
			userId: $userId,
			planText: $planText,
			planType: (string)$this->{self::PARAM_PLAN_TYPE},
		))->run();

		foreach ($result->getErrors() as $error)
		{
			$errors->setError($error);
		}

		return $errors;
	}

	private function extractUserId(): int
	{
		$userId = \CBPHelper::extractFirstUser($this->{self::PARAM_USER_ID}, $this->getDocumentId());

		return (int)$userId;
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
				'Options' => [
					RecordReportType::AI_DAY_PLAN => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TYPE_AI'),
					RecordReportType::ROBOT_DAY_PLAN => Loc::getMessage('TIMEMAN_SAVE_DAY_PLAN_ACTIVITY_PLAN_TYPE_ROBOT'),
				],
				'Default' => RecordReportType::ROBOT_DAY_PLAN,
			],
		];
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
