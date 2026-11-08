<?php

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\WebForm\Internals\FormCounterDailyTable;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Internals\FormTelemetryDailyTable;
use Bitrix\Crm\WebForm\Telemetry\MetricCode;
use Bitrix\Crm\WebForm\Telemetry\Recorder;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\Date;
use Closure;
use Throwable;

final class WebFormMetricCalculator implements MetricCalculatorInterface
{
	public const OPTIONAL_METRIC_KEYS = [
		'averageFillTimeSeconds',
		'fullyFilled',
		'sourceSite',
		'sourceLanding',
		'sourceQr',
		'sourceEmail',
		'sourceMessenger',
		'sourceDirectLink',
		'sourceWidget',
		'sourceUnknown',
		'deviceDesktop',
		'deviceMobile',
		'deviceOther',
		'errorValidation',
		'errorOther',
		'typeEvent',
		'typeStatistics',
		'typeFeedback',
		'typeOther',
	];

	private const METRIC_CODE_TO_KEY = [
		MetricCode::FULLY_FILLED => 'fullyFilled',
		MetricCode::SOURCE_SITE => 'sourceSite',
		MetricCode::SOURCE_LANDING => 'sourceLanding',
		MetricCode::SOURCE_QR => 'sourceQr',
		MetricCode::SOURCE_EMAIL => 'sourceEmail',
		MetricCode::SOURCE_MESSENGER => 'sourceMessenger',
		MetricCode::SOURCE_DIRECT_LINK => 'sourceDirectLink',
		MetricCode::SOURCE_WIDGET => 'sourceWidget',
		MetricCode::SOURCE_UNKNOWN => 'sourceUnknown',
		MetricCode::DEVICE_DESKTOP => 'deviceDesktop',
		MetricCode::DEVICE_MOBILE => 'deviceMobile',
		MetricCode::DEVICE_OTHER => 'deviceOther',
		MetricCode::ERROR_VALIDATION => 'errorValidation',
		MetricCode::ERROR_OTHER => 'errorOther',
		MetricCode::TYPE_EVENT => 'typeEvent',
		MetricCode::TYPE_STATISTICS => 'typeStatistics',
		MetricCode::TYPE_FEEDBACK => 'typeFeedback',
		MetricCode::TYPE_OTHER => 'typeOther',
	];

	private readonly Closure $telemetryStartDateProvider;

	/** @param null|Closure(): ?Date $telemetryStartDateProvider */
	public function __construct(?Closure $telemetryStartDateProvider = null)
	{
		$this->telemetryStartDateProvider = $telemetryStartDateProvider ?? $this->loadTelemetryStartDate(...);
	}

	public function calculate(Date $from, Date $to): array
	{
		$createdForms = $this->countCreatedForms($from, $to);
		$counters = $this->calculateCounters($from, $to);
		$views = (int)($counters['VIEWS_TOTAL'] ?? 0);
		$startFills = (int)($counters['START_FILL_TOTAL'] ?? 0);
		$submissions = (int)($counters['END_FILL_TOTAL'] ?? 0);

		$metrics = [
			'createdForms' => $createdForms,
			'views' => $views,
			'submissions' => $submissions,
			'abandonedFills' => max(0, $startFills - $submissions),
		];

		$telemetryFrom = ($this->telemetryStartDateProvider)();
		if ($telemetryFrom === null)
		{
			return $metrics;
		}

		$reliableFrom = $telemetryFrom->getTimestamp() > $from->getTimestamp() ? $telemetryFrom : $from;
		if ($reliableFrom->getTimestamp() >= $to->getTimestamp())
		{
			return $metrics;
		}

		return $metrics + $this->calculateTelemetry($reliableFrom, $to);
	}

	private function countCreatedForms(Date $from, Date $to): int
	{
		$row = FormTable::query()
			->addSelect(new ExpressionField('CREATED_FORMS_TOTAL', 'COUNT(%s)', 'ID'))
			->where('TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->fetch()
		;

		return (int)($row['CREATED_FORMS_TOTAL'] ?? 0);
	}

	private function calculateCounters(Date $from, Date $to): array
	{
		return FormCounterDailyTable::query()
			->registerRuntimeField(
				new Reference(
					'FORM',
					FormTable::class,
					Join::on('this.FORM_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->addSelect(new ExpressionField('VIEWS_TOTAL', 'SUM(%s)', 'VIEWS'))
			->addSelect(new ExpressionField('START_FILL_TOTAL', 'SUM(%s)', 'START_FILL'))
			->addSelect(new ExpressionField('END_FILL_TOTAL', 'SUM(%s)', 'END_FILL'))
			->where('FORM.TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('DATE_STAT', '>=', $from)
			->where('DATE_STAT', '<', $to)
			->fetch()
		;
	}

	private function calculateTelemetry(Date $from, Date $to): array
	{
		$rows = FormTelemetryDailyTable::query()
			->registerRuntimeField(
				new Reference(
					'FORM',
					FormTable::class,
					Join::on('this.FORM_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->addSelect('METRIC_CODE')
			->addSelect(new ExpressionField('VALUE_TOTAL', 'SUM(%s)', 'VALUE'))
			->where('FORM.TYPE_ID', FormTable::TYPE_DEFAULT)
			->where('DATE_STAT', '>=', $from)
			->where('DATE_STAT', '<', $to)
			->setGroup(['METRIC_CODE'])
			->fetchAll()
		;

		$values = [];
		foreach ($rows as $row)
		{
			$values[(string)$row['METRIC_CODE']] = (int)$row['VALUE_TOTAL'];
		}

		$metrics = [];
		foreach (self::METRIC_CODE_TO_KEY as $metricCode => $key)
		{
			if (array_key_exists($metricCode, $values))
			{
				$metrics[$key] = $values[$metricCode];
			}
		}

		$durationCount = $values[MetricCode::DURATION_COUNT] ?? 0;
		if ($durationCount > 0)
		{
			$metrics['averageFillTimeSeconds'] = intdiv($values[MetricCode::DURATION_SUM] ?? 0, $durationCount);
		}

		return $metrics;
	}

	private function loadTelemetryStartDate(): ?Date
	{
		$value = Option::get('crm', Recorder::TELEMETRY_START_DATE_OPTION, '');
		if ($value === '')
		{
			return null;
		}

		try
		{
			return new Date($value, 'Y-m-d');
		}
		catch (Throwable)
		{
			return null;
		}
	}
}
