<?php

namespace Bitrix\Landing\Markup\Image;

/**
 * Course of the image generation of a markup package: the outcome of every picture the model is
 * asked for, kept in the generation state between the passes of the step.
 *
 * No more than a batch of requests is in flight, the next ones leave as answers come. The whole
 * step has one deadline from its start: past it everything without an outcome is failed by the
 * timeout and a late answer changes nothing - an outcome once settled is final. Pure logic: the
 * step owns the requests, the connector and the clock.
 */
class GenerationProgress
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT = 'sent';
	public const STATUS_GENERATED = 'generated';
	public const STATUS_FAILED = 'failed';

	public const REASON_ERROR = 'error';
	public const REASON_TIMEOUT = 'timeout';
	public const REASON_UNAVAILABLE = 'unavailable';
	public const REASON_LIMIT = 'limit';

	public const DEFAULT_BATCH = 4;
	public const DEFAULT_TIMEOUT = 900;

	private int $startedAt = 0;

	/** @var array{sliderCode: ?string, text: ?string}|null */
	private ?array $limit = null;

	/** @var array<string, array{status: string, requestId?: int, aiFileId?: int, reason?: string}> By token. */
	private array $items = [];

	private function __construct(
		private readonly int $batch,
		private readonly int $timeout,
	)
	{
	}

	/**
	 * @param array $state The stored state, [] for a step that has not started.
	 * @param int $batch Requests in flight at most.
	 * @param int $timeout Seconds from the start to the deadline of the whole step.
	 */
	public static function fromState(
		array $state,
		int $batch = self::DEFAULT_BATCH,
		int $timeout = self::DEFAULT_TIMEOUT
	): self
	{
		$progress = new self(max(1, $batch), max(1, $timeout));
		$progress->startedAt = max(0, (int)($state['startedAt'] ?? 0));
		if (is_array($state['limit'] ?? null))
		{
			$progress->limit = $state['limit'];
		}
		foreach ((array)($state['items'] ?? []) as $token => $item)
		{
			if (!is_array($item) || (string)$token === '')
			{
				continue;
			}
			$progress->items[(string)$token] = self::normalizeItem($item);
		}

		return $progress;
	}

	/**
	 * @return array{startedAt: int, limit?: array, items: array<string, array>}
	 */
	public function toState(): array
	{
		return ['startedAt' => $this->startedAt]
			+ ($this->limit !== null ? ['limit' => $this->limit] : [])
			+ ['items' => $this->items];
	}

	/**
	 * Opens the step with the pictures to generate; the deadline counts from now.
	 * @param string[] $tokens
	 */
	public function start(array $tokens, int $now): void
	{
		$this->startedAt = $now;
		$this->items = [];
		foreach ($tokens as $token)
		{
			if ((string)$token !== '')
			{
				$this->items[(string)$token] = ['status' => self::STATUS_PENDING];
			}
		}
	}

	/**
	 * How many pictures the step asks for.
	 */
	public function countItems(): int
	{
		return count($this->items);
	}

	public function isStarted(): bool
	{
		return $this->startedAt > 0;
	}

	/**
	 * Every picture has its outcome.
	 */
	public function isComplete(): bool
	{
		if (!$this->isStarted())
		{
			return false;
		}
		foreach ($this->items as $item)
		{
			if (!self::isSettled($item))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Tokens to send now: the pending ones, no more than the batch minus the requests in flight.
	 * @return string[]
	 */
	public function nextToSend(): array
	{
		if (!$this->isStarted())
		{
			return [];
		}

		$slots = $this->batch - $this->countByStatus(self::STATUS_SENT);
		$tokens = [];
		foreach ($this->items as $token => $item)
		{
			if ($slots <= 0)
			{
				break;
			}
			if ($item['status'] === self::STATUS_PENDING)
			{
				// A numeric token turns into an int key of the array.
				$tokens[] = (string)$token;
				$slots--;
			}
		}

		return $tokens;
	}

	public function markSent(string $token, int $requestId): void
	{
		if (($this->items[$token]['status'] ?? '') === self::STATUS_PENDING)
		{
			$this->items[$token] = ['status' => self::STATUS_SENT, 'requestId' => $requestId];
		}
	}

	public function markGenerated(string $token, int $aiFileId): void
	{
		$this->settle($token, ['status' => self::STATUS_GENERATED, 'aiFileId' => $aiFileId]);
	}

	public function markFailed(string $token, string $reason): void
	{
		$this->settle($token, ['status' => self::STATUS_FAILED, 'reason' => $reason]);
	}

	/**
	 * Fails every picture without an outcome: the model is unavailable or the AI limit is spent.
	 * @param array{sliderCode: ?string, text: ?string}|null $limit The spent limit, for the answer of the tools.
	 */
	public function failRest(string $reason, ?array $limit = null): void
	{
		foreach (array_keys($this->items) as $token)
		{
			$this->markFailed((string)$token, $reason);
		}
		if ($limit !== null)
		{
			$this->limit = $limit;
		}
	}

	/**
	 * Past the deadline the rest is failed by the timeout.
	 * @return bool Whether the deadline has passed.
	 */
	public function expire(int $now): bool
	{
		if (!$this->isStarted() || $now < $this->startedAt + $this->timeout)
		{
			return false;
		}

		$this->failRest(self::REASON_TIMEOUT);

		return true;
	}

	public function findTokenByRequest(int $requestId): ?string
	{
		if ($requestId <= 0)
		{
			return null;
		}
		foreach ($this->items as $token => $item)
		{
			if (($item['requestId'] ?? 0) === $requestId)
			{
				return (string)$token;
			}
		}

		return null;
	}

	/**
	 * Settled pictures by token: {status: generated, aiFileId} or {status: failed, reason}.
	 * @return array<string, array{status: string, aiFileId?: int, reason?: string}>
	 */
	public function getOutcomes(): array
	{
		$outcomes = [];
		foreach ($this->items as $token => $item)
		{
			if (!self::isSettled($item))
			{
				continue;
			}
			$outcomes[$token] = $item['status'] === self::STATUS_GENERATED
				? ['status' => $item['status'], 'aiFileId' => (int)($item['aiFileId'] ?? 0)]
				: ['status' => $item['status'], 'reason' => (string)($item['reason'] ?? '')];
		}

		return $outcomes;
	}

	/**
	 * The spent AI limit that failed the rest, null when none did.
	 * @return array{sliderCode: ?string, text: ?string}|null
	 */
	public function getLimit(): ?array
	{
		return $this->limit;
	}

	/**
	 * An outcome is final: a settled picture is not changed by a later answer.
	 */
	private function settle(string $token, array $outcome): void
	{
		$item = $this->items[$token] ?? null;
		if ($item === null || self::isSettled($item))
		{
			return;
		}

		$this->items[$token] = ['status' => $outcome['status']]
			+ (isset($item['requestId']) ? ['requestId' => $item['requestId']] : [])
			+ $outcome;
	}

	private function countByStatus(string $status): int
	{
		$count = 0;
		foreach ($this->items as $item)
		{
			if ($item['status'] === $status)
			{
				$count++;
			}
		}

		return $count;
	}

	private static function isSettled(array $item): bool
	{
		return in_array($item['status'], [self::STATUS_GENERATED, self::STATUS_FAILED], true);
	}

	/**
	 * @return array{status: string, requestId?: int, aiFileId?: int, reason?: string}
	 */
	private static function normalizeItem(array $item): array
	{
		$status = (string)($item['status'] ?? '');
		$statuses = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_GENERATED, self::STATUS_FAILED];
		if (!in_array($status, $statuses, true))
		{
			$status = self::STATUS_PENDING;
		}

		return ['status' => $status]
			+ (isset($item['requestId']) ? ['requestId' => (int)$item['requestId']] : [])
			+ ($status === self::STATUS_GENERATED ? ['aiFileId' => (int)($item['aiFileId'] ?? 0)] : [])
			+ ($status === self::STATUS_FAILED ? ['reason' => (string)($item['reason'] ?? '')] : []);
	}
}
