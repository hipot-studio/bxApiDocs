<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Internal\Digest\DigestDeadline;

/**
 * The time a tool call of a digest run may spend on Superset, shared by all its reads: with a timeout of
 * its own, a call that reads the report card and then the data would wait twice as long as the AI service
 * waits on the tool. Outside a run the reads keep the timeouts they had.
 */
final class DigestCallBudget
{
	public const SECONDS = 15;

	private function __construct(private readonly ?DigestDeadline $deadline)
	{
	}

	public static function start(?string $runId): self
	{
		return new self($runId === null ? null : DigestDeadline::in(self::SECONDS));
	}

	/**
	 * @param int $unpinnedTimeout Timeout the read keeps outside a digest run.
	 */
	public function getStreamTimeout(int $unpinnedTimeout): int
	{
		return $this->deadline?->getStreamTimeout() ?? $unpinnedTimeout;
	}

	public function isExhausted(): bool
	{
		return $this->deadline?->isExhausted() ?? false;
	}
}
