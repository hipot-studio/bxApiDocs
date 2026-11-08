<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\WebForm\Internals\FormTelemetryAttemptTable;
use Bitrix\Crm\WebForm\Internals\FormTelemetryDailyTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

final class Recorder implements RecorderInterface
{
	public const TELEMETRY_START_DATE_OPTION = 'webform_telemetry_start_date';
	public const DAILY_ATTEMPT_LIMIT_OPTION = 'webform_telemetry_daily_attempt_limit';

	private const MODULE_ID = 'crm';
	private const ATTEMPT_TTL_SECONDS = 86_400;
	private const MIN_DURATION_SECONDS = 1;
	private const MAX_DURATION_SECONDS = 7_200;
	private const DEFAULT_DAILY_ATTEMPT_LIMIT = 50_000;

	private readonly SubmissionClassifier $classifier;
	private readonly Connection $connection;
	private readonly Closure $clock;
	private readonly LoggerInterface $logger;

	/** @param null|Closure(): DateTime $clock */
	public function __construct(
		?SubmissionClassifier $classifier = null,
		?Connection $connection = null,
		?Closure $clock = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->classifier = $classifier ?? new SubmissionClassifier();
		$this->connection = $connection ?? Application::getConnection();
		$this->clock = $clock ?? static fn(): DateTime => new DateTime();
		$this->logger = $logger ?? Container::getInstance()->getLogger('WebFormTelemetry');
	}

	public function start(string $attemptId, int $formId, string $sourceHint, ?string $userAgent): void
	{
		if (!$this->isUuidV4($attemptId) || $formId <= 0)
		{
			return;
		}

		$this->executeSafely('start', $attemptId, function () use ($attemptId, $formId, $sourceHint, $userAgent): void {
			$this->transactional(function () use ($attemptId, $formId, $sourceHint, $userAgent): void {
				$now = $this->now();
				$isCreated = $this->createAttempt(
					$attemptId,
					$formId,
					$now,
					$this->classifier->classifySource($sourceHint, []),
					$this->classifier->classifyDevice($userAgent),
				);
				if ($isCreated)
				{
					$this->ensureCollectionStartDate($now);
				}
			});
		});
	}

	public function markError(string $attemptId, string $errorType): void
	{
		$metricCode = MetricCode::forError($errorType);
		$errorField = match ($errorType)
		{
			'validation' => 'VALIDATION_ERROR',
			'other' => 'OTHER_ERROR',
			default => null,
		};
		if (!$this->isUuidV4($attemptId) || $metricCode === null || $errorField === null)
		{
			return;
		}

		$this->executeSafely('markError', $attemptId, function () use ($attemptId, $metricCode, $errorField): void {
			$this->transactional(function () use ($attemptId, $metricCode, $errorField): void {
				if ($this->transitionAttempt($attemptId, [$errorField => true], [$errorField => false]) !== 1)
				{
					return;
				}

				$row = FormTelemetryAttemptTable::getRowById($attemptId);
				if (!is_array($row))
				{
					return;
				}

				$now = $this->now();
				$this->incrementDaily($now, (int)$row['FORM_ID'], [$metricCode => 1]);
				$this->ensureCollectionStartDate($now);
			});
		});
	}

	public function complete(string $attemptId, int $formId, array $fields, ?string $templateId): void
	{
		if (!$this->isUuidV4($attemptId) || $formId <= 0)
		{
			return;
		}

		$this->executeSafely('complete', $attemptId, function () use ($attemptId, $formId, $fields, $templateId): void {
			$this->transactional(function () use ($attemptId, $formId, $fields, $templateId): void {
				if (!$this->completeAttempt($attemptId, $formId))
				{
					return;
				}

				$row = FormTelemetryAttemptTable::getRowById($attemptId);
				if (!is_array($row))
				{
					return;
				}

				$now = $this->now();
				$this->incrementCompletionMetrics($now, $formId, $row, $fields, $templateId);
				$this->ensureCollectionStartDate($now);
			});
		});
	}

	private function completeAttempt(string $attemptId, int $formId): bool
	{
		$values = ['COMPLETED' => true];
		$expected = ['FORM_ID' => $formId, 'COMPLETED' => false];
		if ($this->transitionAttempt($attemptId, $values, $expected) === 1)
		{
			return true;
		}

		if (is_array(FormTelemetryAttemptTable::getRowById($attemptId)))
		{
			return false;
		}

		$isCreated = $this->createAttempt(
			$attemptId,
			$formId,
			null,
			SubmissionClassifier::SOURCE_UNKNOWN,
			SubmissionClassifier::DEVICE_OTHER,
		);

		return $isCreated && $this->transitionAttempt($attemptId, $values, $expected) === 1;
	}

	/** Single entry point for new attempts: the daily quota cannot be skipped by a caller. */
	private function createAttempt(
		string $attemptId,
		int $formId,
		?DateTime $dateStart,
		string $source,
		string $device,
	): bool
	{
		if (!$this->consumeDailyAttemptQuota($this->now(), $formId))
		{
			return false;
		}

		$this->insertAttemptIfMissing($attemptId, $formId, $dateStart, $source, $device);

		return true;
	}

	private function insertAttemptIfMissing(
		string $attemptId,
		int $formId,
		?DateTime $dateStart,
		string $source,
		string $device,
	): void
	{
		$expireTimestamp = $this->now()->getTimestamp() + self::ATTEMPT_TTL_SECONDS;
		$merge = $this->connection->getSqlHelper()->prepareMerge(
			FormTelemetryAttemptTable::getTableName(),
			['ATTEMPT_ID'],
			[
				'ATTEMPT_ID' => $attemptId,
				'FORM_ID' => $formId,
				'DATE_START' => $dateStart,
				'DATE_EXPIRE' => DateTime::createFromTimestamp($expireTimestamp),
				'SOURCE' => $source,
				'DEVICE' => $device,
				'VALIDATION_ERROR' => false,
				'OTHER_ERROR' => false,
				'COMPLETED' => false,
			],
			['ATTEMPT_ID' => $attemptId],
		);

		$this->executeMerge($merge);
	}

	private function transitionAttempt(string $attemptId, array $values, array $expected): int
	{
		$filter = Query::filter()->where('ATTEMPT_ID', $attemptId);
		foreach ($expected as $field => $value)
		{
			$filter->where($field, $value);
		}

		$tableName = FormTelemetryAttemptTable::getTableName();
		$helper = $this->connection->getSqlHelper();
		[$update, $binds] = $helper->prepareUpdate($tableName, $values);
		$where = Query::buildFilterSql(FormTelemetryAttemptTable::getEntity(), $filter);
		$this->connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update . ' WHERE ' . $where,
			$binds,
		);

		return $this->connection->getAffectedRowsCount();
	}

	private function incrementCompletionMetrics(
		DateTime $now,
		int $formId,
		array $attempt,
		array $fields,
		?string $templateId,
	): void
	{
		$increments = [
			MetricCode::forSource((string)$attempt['SOURCE']) => 1,
			MetricCode::forDevice((string)$attempt['DEVICE']) => 1,
			MetricCode::forType($this->classifier->classifyType($templateId)) => 1,
		];

		if ($this->classifier->isFullyFilled($fields))
		{
			$increments[MetricCode::FULLY_FILLED] = 1;
		}

		$duration = $this->measureDuration($now, $attempt['DATE_START'] ?? null);
		if ($duration !== null)
		{
			$increments[MetricCode::DURATION_SUM] = $duration;
			$increments[MetricCode::DURATION_COUNT] = 1;
		}

		$this->incrementDaily($now, $formId, $increments);
	}

	private function measureDuration(DateTime $now, mixed $dateStart): ?int
	{
		if (!$dateStart instanceof DateTime)
		{
			return null;
		}

		$duration = $now->getTimestamp() - $dateStart->getTimestamp();
		if ($duration < self::MIN_DURATION_SECONDS || $duration > self::MAX_DURATION_SECONDS)
		{
			return null;
		}

		return $duration;
	}

	/** @param array<string, int> $increments */
	private function incrementDaily(DateTime $now, int $formId, array $increments): void
	{
		if ($increments === [])
		{
			return;
		}

		$tableName = FormTelemetryDailyTable::getTableName();
		$dateStat = new Date($now->format('Y-m-d'), 'Y-m-d');
		$rows = [];
		foreach ($increments as $metricCode => $value)
		{
			$rows[] = [
				'DATE_STAT' => $dateStat,
				'FORM_ID' => $formId,
				'METRIC_CODE' => $metricCode,
				'VALUE' => $value,
			];
		}

		$this->connection->queryExecute(
			$this->connection->getSqlHelper()->prepareMergeValues(
				$tableName,
				['DATE_STAT', 'FORM_ID', 'METRIC_CODE'],
				$rows,
				['VALUE' => new SqlExpression('?#.?# + ?v', $tableName, 'VALUE', 'VALUE')],
			),
		);
	}

	private function consumeDailyAttemptQuota(DateTime $now, int $formId): bool
	{
		$dateStat = new Date($now->format('Y-m-d'), 'Y-m-d');
		if ($this->increaseQuotaWithinLimit($dateStat, $formId) === 1)
		{
			return true;
		}

		$this->insertQuotaIfMissing($dateStat, $formId);

		return $this->increaseQuotaWithinLimit($dateStat, $formId) === 1;
	}

	private function insertQuotaIfMissing(Date $dateStat, int $formId): void
	{
		$merge = $this->connection->getSqlHelper()->prepareMerge(
			FormTelemetryDailyTable::getTableName(),
			['DATE_STAT', 'FORM_ID', 'METRIC_CODE'],
			[
				'DATE_STAT' => $dateStat,
				'FORM_ID' => $formId,
				'METRIC_CODE' => MetricCode::PUBLIC_ATTEMPT_QUOTA,
				'VALUE' => 0,
			],
			['METRIC_CODE' => MetricCode::PUBLIC_ATTEMPT_QUOTA],
		);

		$this->executeMerge($merge);
	}

	private function increaseQuotaWithinLimit(Date $dateStat, int $formId): int
	{
		$filter = Query::filter()
			->where('DATE_STAT', $dateStat)
			->where('FORM_ID', $formId)
			->where('METRIC_CODE', MetricCode::PUBLIC_ATTEMPT_QUOTA)
			->where('VALUE', '<', $this->getDailyAttemptLimit())
		;

		$tableName = FormTelemetryDailyTable::getTableName();
		$helper = $this->connection->getSqlHelper();
		[$update, $binds] = $helper->prepareUpdate($tableName, ['VALUE' => new SqlExpression('?# + 1', 'VALUE')]);
		$where = Query::buildFilterSql(FormTelemetryDailyTable::getEntity(), $filter);
		$this->connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update . ' WHERE ' . $where,
			$binds,
		);

		return $this->connection->getAffectedRowsCount();
	}

	private function getDailyAttemptLimit(): int
	{
		$limit = (int)Option::get(
			self::MODULE_ID,
			self::DAILY_ATTEMPT_LIMIT_OPTION,
			(string)self::DEFAULT_DAILY_ATTEMPT_LIMIT,
		);

		return $limit > 0 ? $limit : self::DEFAULT_DAILY_ATTEMPT_LIMIT;
	}

	private function executeMerge(string|array $merge): void
	{
		foreach ((array)$merge as $statement)
		{
			if (is_string($statement) && $statement !== '')
			{
				$this->connection->queryExecute($statement);
			}
		}
	}

	private function ensureCollectionStartDate(DateTime $now): void
	{
		if (Option::get(self::MODULE_ID, self::TELEMETRY_START_DATE_OPTION, '') === '')
		{
			Option::set(self::MODULE_ID, self::TELEMETRY_START_DATE_OPTION, $now->format('Y-m-d'));
		}
	}

	private function transactional(Closure $operation): void
	{
		$started = false;
		try
		{
			$this->connection->startTransaction();
			$started = true;
			$operation();
			$this->connection->commitTransaction();
		}
		catch (Throwable $error)
		{
			if ($started)
			{
				try
				{
					$this->connection->rollbackTransaction();
				}
				catch (Throwable)
				{
				}
			}

			throw $error;
		}
	}

	private function executeSafely(string $operation, string $attemptId, Closure $callback): void
	{
		try
		{
			$callback();
		}
		catch (Throwable $error)
		{
			try
			{
				$this->logger->error(
					'Failed to record CRM web form telemetry',
					[
						'operation' => $operation,
						'attemptId' => $attemptId,
						'exception' => $error,
					],
				);
			}
			catch (Throwable)
			{
			}
		}
	}

	private function now(): DateTime
	{
		return ($this->clock)();
	}

	private function isUuidV4(string $attemptId): bool
	{
		return preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$attemptId,
		) === 1;
	}
}
