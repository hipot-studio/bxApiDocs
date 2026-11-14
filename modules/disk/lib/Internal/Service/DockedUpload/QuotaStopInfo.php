<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;

/**
 * Immutable classification of a quota stop produced by {@see QuotaScenario}.
 *
 * It carries the wire code and the info-helper the client renders the stop with,
 * AND the durable state the queue must land in ({@see self::getQueueStatus()}).
 * The state belongs here because the MECHANISM chooses it, not the point that
 * caught the signal: a pre-check, the domain write and the transport belt all
 * apply what this object says, so one shortage cannot end in two different states
 * depending on where it was noticed.
 */
final class QuotaStopInfo
{
	public function __construct(
		private readonly string $scenario,
		private readonly string $errorCode,
		private readonly ?string $infoHelperCode,
		private readonly bool $tariffButtonAvailable,
		private readonly QueueStatus $queueStatus,
	)
	{
	}

	/**
	 * The state the stopped queue is written down with: stopped_by_quota for the cloud
	 * tariff limit (the commercial way out), requires_action for every other shortage.
	 */
	public function getQueueStatus(): QueueStatus
	{
		return $this->queueStatus;
	}

	public function getScenario(): string
	{
		return $this->scenario;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getInfoHelperCode(): ?string
	{
		return $this->infoHelperCode;
	}

	public function isTariffButtonAvailable(): bool
	{
		return $this->tariffButtonAvailable;
	}

	public function isCloud(): bool
	{
		return $this->scenario === QuotaScenario::SCENARIO_CLOUD;
	}
}
