<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\MetricThreshold;

final class RuThresholds implements RegionThresholds
{
	private readonly array $thresholds;
	private readonly MetricThreshold $reservedHours;

	public function __construct()
	{
		$this->thresholds = [
			'vibecodeconnector.cowork_active_minutes' => new MetricThreshold(180, 9600),
			'im.received_reactions' => new MetricThreshold(20, 1500),
			'ai.chat_requests_total' => new MetricThreshold(8, 100),
			'tasks.created_audio_tasks' => new MetricThreshold(4, 80),
			'tasks.completed_tasks' => new MetricThreshold(20, 1200),
			'socialnetwork.created_ai_projects' => new MetricThreshold(1, 20),
			'im.sent_messages' => new MetricThreshold(60, 4000),
			'crm.closed_deals' => new MetricThreshold(15, 1400),
			'disk.created_boards' => new MetricThreshold(1, 6),
			'bizproc.started_workflows' => new MetricThreshold(3, 100),
			'stafftrack.checkins' => new MetricThreshold(2, 180),
			'booking.created_bookings' => new MetricThreshold(3, 500),
			'note.created_collections' => new MetricThreshold(1, 5),
			'call.calls_count' => new MetricThreshold(2, 300),
		];
		$this->reservedHours = new MetricThreshold(3, 160);
	}

	public function getThreshold(string $code): ?MetricThreshold
	{
		return $this->thresholds[$code] ?? null;
	}

	public function getReservedHours(): ?MetricThreshold
	{
		return $this->reservedHours;
	}

	public function isSupported(): bool
	{
		return true;
	}
}
