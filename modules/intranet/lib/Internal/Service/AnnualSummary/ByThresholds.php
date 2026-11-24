<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\MetricThreshold;

final class ByThresholds implements RegionThresholds
{
	private readonly array $thresholds;
	private readonly MetricThreshold $reservedHours;

	public function __construct()
	{
		$this->thresholds = [
			'vibecodeconnector.cowork_active_minutes' => new MetricThreshold(180, 9600),
			'im.received_reactions' => new MetricThreshold(15, 900),
			'ai.chat_requests_total' => new MetricThreshold(2, 20),
			'tasks.created_audio_tasks' => new MetricThreshold(2, 30),
			'tasks.completed_tasks' => new MetricThreshold(5, 420),
			'socialnetwork.created_ai_projects' => new MetricThreshold(1, 10),
			'im.sent_messages' => new MetricThreshold(80, 3500),
			'crm.closed_deals' => new MetricThreshold(8, 1000),
			'disk.created_boards' => new MetricThreshold(1, 5),
			'bizproc.started_workflows' => new MetricThreshold(5, 160),
			'stafftrack.checkins' => new MetricThreshold(2, 150),
			'booking.created_bookings' => new MetricThreshold(3, 140),
			'note.created_collections' => new MetricThreshold(1, 5),
			'call.calls_count' => new MetricThreshold(2, 200),
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
