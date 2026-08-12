<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\V2\Public\Command\FullReport\AddCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\UpdateCommand;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;

class CBPFillFullReportActivity extends BaseActivity
{
	private const PARAM_USER_ID = 'UserId';
	private const PARAM_REPORT_EXTENDED = 'ReportExtended';
	private const PARAM_TYPE = 'Type';

	protected static $requiredModules = ['timeman'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_USER_ID => null,
			self::PARAM_REPORT_EXTENDED => '',
			self::PARAM_TYPE => Loader::includeModule('timeman') ? RecordReportType::ROBOT_REPORT : null,
		];
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
			return $errors;
		}

		$provider = new FullReportProvider();
		$report = $provider->getReportToSend($userId, true);

		$reportExtended = $this->convertMarkdownToBbCode((string)$this->{self::PARAM_REPORT_EXTENDED});

		$command = $report->id > 0
			? new UpdateCommand(
				reportId: $report->id,
				reportText: $report->report,
				reportExtended: $reportExtended,
				type: (string)$this->{self::PARAM_TYPE},
				plansText: $report->plans,
				tasks: $report->tasks,
				events: $report->events,
				files: $report->files,
				dateFrom: $report->dateFrom,
				dateTo: $report->dateTo,
				mark: $report->mark,
			)
			: new AddCommand(
				userId: $userId,
				reportText: $report->report,
				reportExtended: $reportExtended,
				type: (string)$this->{self::PARAM_TYPE},
				plansText: $report->plans,
				tasks: $report->tasks,
				events: $report->events,
				files: $report->files,
				autoFillDailyReports: false,
				dateFrom: $report->dateFrom,
				dateTo: $report->dateTo,
			)
		;

		$result = $command->run();

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
		return static::getPropertiesMap([]);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER_ID => [
				'Name' => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_USER_PROPERTY') ?? '',
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_REPORT_EXTENDED => [
				'Name' => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_REPORT_EXTENDED_PROPERTY') ?? '',
				'FieldName' => 'report_extended',
				'Type' => FieldType::TEXT,
			],
			self::PARAM_TYPE => [
				'Name' => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_TYPE_PROPERTY') ?? '',
				'FieldName' => 'type',
				'Type' => FieldType::SELECT,
				'Options' => [
					RecordReportType::AI_REPORT => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_TYPE_AI'),
					RecordReportType::ROBOT_REPORT => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_TYPE_ROBOT'),
				],
				'Default' => RecordReportType::ROBOT_REPORT,
			],
		];
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (\CBPHelper::isEmptyValue($testProperties[self::PARAM_USER_ID] ?? null))
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => self::PARAM_USER_ID,
				'message' => Loc::getMessage('TIMEMAN_FILL_FULL_REPORT_ACTIVITY_ERROR_EMPTY_USER') ?? '',
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
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
