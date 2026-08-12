<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter as ReportFilter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\ReportProvider;
use Bitrix\Timeman\V2\Internal\Service\ReportTextNormalizerService;

class CBPGetFullReportActivity extends BaseActivity
{
	private const PARAM_USER_ID = 'UserId';
	private const RETURN_PARAM_REPORT_TEXT = 'ReportText';
	private const RETURN_PARAM_REPORT_EXTENDED = 'ReportExtended';
	private const RETURN_PARAM_TYPE = 'Type';

	protected static $requiredModules = ['timeman'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_USER_ID => null,
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

		$reportText = $this->getReportTextNormalizer()->flattenParagraphsForChat((string)($report->report ?? ''));
		$reportExtended = $this->getReportTextNormalizer()->flattenParagraphsForChat((string)($report->reportExtended ?? ''));
		$type = (string)($report->type ?? '');

		if ($report->id === 0)
		{
			$reportExtended = $this->buildReportExtendedFromRecords(
				$userId,
				$report->dateFrom,
				$report->dateTo,
			);
		}

		$this->setProperty(self::RETURN_PARAM_REPORT_TEXT, $reportText);
		$this->setProperty(self::RETURN_PARAM_REPORT_EXTENDED, $reportExtended);
		$this->setProperty(self::RETURN_PARAM_TYPE, $type);

		return $errors;
	}

	private function buildReportExtendedFromRecords(int $userId, ?int $dateFrom, ?int $dateTo): string
	{
		$dateFrom = $dateFrom !== null ? (int)strtotime('today', $dateFrom) : null;
		$dateTo = $dateTo !== null ? (int)strtotime('tomorrow', $dateTo) - 1 : null;
		if ($dateFrom === null || $dateTo === null)
		{
			return '';
		}

		$recordIds = (new RecordProvider())->getRecordIdsForPeriod($userId, $dateFrom, $dateTo);
		if (empty($recordIds))
		{
			return '';
		}

		$reports = (new ReportProvider())->getReports(
			$userId,
			new ListParams(
				pager: new Pager(count($recordIds)),
				filter: new ReportFilter(
					recordId: $recordIds,
					withAi: true,
				),
			),
		);

		$entries = [];
		foreach ($reports as $report)
		{
			$aiText = (string)($report->reportExtended ?? '');
			$employeeText = $report->report;

			$mainText = $aiText !== '' ? $aiText : $employeeText;
			if ($mainText === '')
			{
				continue;
			}

			$entries[] = [
				'timestamp' => $report->timestamp,
				'text' => $this->formatReportEntry(
					$report->timestamp,
					$mainText,
					$aiText !== '' ? $employeeText : null,
				),
			];
		}

		usort($entries, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

		return implode("\n\n", array_column($entries, 'text'));
	}

	private function formatReportEntry(int $timestamp, string $main, ?string $comment): string
	{
		$dateLabel = ConvertTimeStamp($timestamp, 'SHORT');
		$message = '[b]' . $dateLabel . '[/b]' . "\n" . $this->getReportTextNormalizer()->flattenParagraphsForChat($main);

		/*
		if ($comment !== null && $comment !== '')
		{
			$message .= "\n" . (Loc::getMessage('TIMEMAN_GET_FULL_REPORT_ACTIVITY_EMPLOYEE_COMMENT') ?? '')
				. "\n" . $comment;
		}
		*/

		return $message;
	}

	private function getReportTextNormalizer(): ReportTextNormalizerService
	{
		return new ReportTextNormalizerService();
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
				'Name' => Loc::getMessage('TIMEMAN_GET_FULL_REPORT_ACTIVITY_USER_PROPERTY') ?? '',
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
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
				'message' => Loc::getMessage('TIMEMAN_GET_FULL_REPORT_ACTIVITY_ERROR_EMPTY_USER') ?? '',
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}
}
