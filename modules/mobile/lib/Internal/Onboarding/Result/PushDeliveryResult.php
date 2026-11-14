<?php

namespace Bitrix\Mobile\Internal\Onboarding\Result;

use Bitrix\Main\Result;

/**
 * Outcome of one push per channel it was sent through. Sender::sendImmediate() merges
 * the errors of every channel into a single Result, where a silent system notification
 * is indistinguishable from a push nobody received - and the campaign may only treat a
 * push as sent when at least one channel delivered it.
 *
 * The errors of the failed channels are kept, so a delivery that reached nobody still
 * carries the reason; a partial one is reported through getFailureSummary().
 */
class PushDeliveryResult extends Result
{
	/** @var string[] */
	private array $deliveredChannels = [];

	/** @var array<string, string> */
	private array $failureReasons = [];

	public function registerChannel(string $channel, Result $channelResult): self
	{
		if ($channelResult->isSuccess())
		{
			$this->deliveredChannels[] = $channel;

			return $this;
		}

		$this->failureReasons[$channel] = (string)$channelResult->getError()?->getMessage();
		$this->addErrors($channelResult->getErrors());

		return $this;
	}

	public function wasDelivered(): bool
	{
		return $this->deliveredChannels !== [];
	}

	public function hasFailedChannel(): bool
	{
		return $this->failureReasons !== [];
	}

	/**
	 * Channels that accepted the push, for the trace of a dev-mode pass: the outcome of
	 * the pipeline says a push was delivered, this says by whom.
	 */
	public function getDeliverySummary(): string
	{
		return implode(', ', $this->deliveredChannels);
	}

	/**
	 * Failed channels with the reason of each - the log line of a partial delivery.
	 */
	public function getFailureSummary(): string
	{
		$parts = [];

		foreach ($this->failureReasons as $channel => $reason)
		{
			$parts[] = $reason === '' ? $channel : $channel . ' (' . $reason . ')';
		}

		return implode(', ', $parts);
	}
}
