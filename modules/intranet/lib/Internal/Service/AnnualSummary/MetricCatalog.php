<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Main\Localization\Loc;

final class MetricCatalog
{
	private const CANDIDATES = [
		'vibecodeconnector.cowork_active_minutes' => [1, 'INTRANET_AS_ADMIN_METRIC_ACTIVE_HOURS'],
		'im.received_reactions' => [2, 'INTRANET_AS_ADMIN_METRIC_RECEIVED_REACTIONS'],
		'ai.chat_requests_total' => [3, 'INTRANET_AS_ADMIN_METRIC_CHAT_REQUESTS'],
		'tasks.created_audio_tasks' => [4, 'INTRANET_AS_ADMIN_METRIC_AUDIO_TASKS'],
		'tasks.completed_tasks' => [5, 'INTRANET_AS_ADMIN_METRIC_COMPLETED_TASKS'],
		'socialnetwork.created_ai_projects' => [6, 'INTRANET_AS_ADMIN_METRIC_CREATED_PROJECTS'],
		'im.sent_messages' => [7, 'INTRANET_AS_ADMIN_METRIC_SENT_MESSAGES'],
		'crm.closed_deals' => [8, 'INTRANET_AS_ADMIN_METRIC_CLOSED_DEALS'],
		'disk.created_boards' => [9, 'INTRANET_AS_ADMIN_METRIC_CREATED_BOARDS'],
		'bizproc.started_workflows' => [10, 'INTRANET_AS_ADMIN_METRIC_STARTED_WORKFLOWS'],
		'stafftrack.checkins' => [11, 'INTRANET_AS_ADMIN_METRIC_CHECKINS'],
		'note.created_collections' => [13, 'INTRANET_AS_ADMIN_METRIC_CREATED_COLLECTIONS'],
		'call.calls_count' => [14, 'INTRANET_AS_ADMIN_METRIC_PARSED_CALLS'],
	];

	private const DETAILS = [
		'ai.chat_requests_total' => [
			'ai.chat_requests_peak_month' => 'INTRANET_AS_ADMIN_DETAIL_CHAT_PEAK_MONTH',
		],
		'tasks.completed_tasks' => [
			'tasks.most_productive_month' => 'INTRANET_AS_ADMIN_DETAIL_TASKS_PEAK_MONTH',
			'tasks.most_productive_month_completed_tasks' => 'INTRANET_AS_ADMIN_DETAIL_TASKS_PEAK_MONTH_TOTAL',
		],
		'im.sent_messages' => [
			'im.most_active_month' => 'INTRANET_AS_ADMIN_DETAIL_MESSAGES_PEAK_MONTH',
			'im.most_active_month_messages' => 'INTRANET_AS_ADMIN_DETAIL_MESSAGES_PEAK_MONTH_TOTAL',
		],
		'crm.closed_deals' => [
			'crm.closed_deals.best_month' => 'INTRANET_AS_ADMIN_DETAIL_DEALS_PEAK_MONTH',
			'crm.closed_deals.best_month_total' => 'INTRANET_AS_ADMIN_DETAIL_DEALS_PEAK_MONTH_TOTAL',
		],
	];

	public function getMetrics(): array
	{
		Loc::loadMessages(__FILE__);
		$metrics = [];
		foreach (self::CANDIDATES as $code => $candidate)
		{
			$details = [];
			foreach (self::DETAILS[$code] ?? [] as $detailCode => $message)
			{
				$details[] = [
					'code' => $detailCode,
					'name' => Loc::getMessage($message),
					'details' => [],
				];
			}
			$metrics[] = [
				'code' => $code,
				'name' => Loc::getMessage($candidate[1]),
				'details' => $details,
			];
		}

		return $metrics;
	}

	public function getOrder(string $code): ?int
	{
		return self::CANDIDATES[$code][0] ?? null;
	}

	public function getReservedHours(): array
	{
		return [
			'order' => 1,
			'active' => true,
			'code' => 'vibecodeconnector.cowork_active_minutes',
			'provider' => 'vibecodeconnector.cowork',
		];
	}
}
