<?php

declare(strict_types=1);

namespace Bitrix\AI\Public\Dto;

/**
 * Limit details of an employee for the limit wall, read from an element of data.users of
 * AiLimitsService::getUser.
 *
 * The window is the senior exhausted one (month, week, 5 hours); the reset time is the latest of
 * blockedUntil and the resetAt of the exhausted windows. Without exhausted windows the platform's
 * exhaustedWindowId, then mostLoadedWindowId is taken, and the reset time is the latest of
 * blockedUntil and the resetAt of that window, exhausted or not. Details without a reset time are
 * not given: the wall never shows a window without a date.
 */
final class SeatQuotaDetails
{
	/** Seniority of the employee windows: the longer the window, the later the limit comes back. */
	private const WINDOW_RANK = ['month' => 3, 'week' => 2, '5h' => 1];
	private const STATUS_EXHAUSTED = 'exhausted';
	private const ISO_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

	private function __construct(
		/** The tariff as the platform sent it. */
		public readonly string $tier,
		/** 5h|week|month as the platform sent it; null when the platform names no window. */
		public readonly ?string $windowId,
		/** UTC. */
		public readonly \DateTimeImmutable $resetAt,
		/** The next tariff as the platform sent it. */
		public readonly ?string $nextTier,
	)
	{
	}

	public static function fromPlatformData(array $data): self|QuotaDetailsUnavailableReason
	{
		try
		{
			return self::interpret($data);
		}
		catch (\Throwable)
		{
			return QuotaDetailsUnavailableReason::Malformed;
		}
	}

	private static function interpret(array $data): self|QuotaDetailsUnavailableReason
	{
		$linked = $data['linked'] ?? null;
		if ($linked !== null && !is_bool($linked))
		{
			return QuotaDetailsUnavailableReason::Malformed;
		}

		if ($linked === false)
		{
			return QuotaDetailsUnavailableReason::Unlinked;
		}

		$tier = $data['tier'] ?? null;
		$nextTier = $data['nextTier'] ?? null;
		$exhaustedWindowId = $data['exhaustedWindowId'] ?? null;
		$mostLoadedWindowId = $data['mostLoadedWindowId'] ?? null;
		if (
			!is_string($tier)
			|| $tier === ''
			|| !self::isOptionalString($nextTier)
			|| !self::isOptionalString($exhaustedWindowId)
			|| !self::isOptionalString($mostLoadedWindowId)
		)
		{
			return QuotaDetailsUnavailableReason::Malformed;
		}

		$blockedUntil = self::parseDateTime($data['blockedUntil'] ?? null);
		$windows = self::parseWindows($data['windows'] ?? null);

		$exhausted = array_values(array_filter($windows, static fn (array $window): bool => $window['exhausted']));
		if ($exhausted !== [])
		{
			$windowId = self::seniorWindowId($exhausted);
			$resetAt = self::latest([$blockedUntil, ...array_column($exhausted, 'resetAt')]);
		}
		else
		{
			$windowId = $exhaustedWindowId ?? $mostLoadedWindowId;
			$resetAt = self::latest([$blockedUntil, self::windowResetAt($windows, $windowId)]);
		}

		if ($resetAt === null)
		{
			return QuotaDetailsUnavailableReason::NoResetAt;
		}

		return new self($tier, $windowId, $resetAt, $nextTier);
	}

	/**
	 * @param list<array{id: string, exhausted: bool, resetAt: ?\DateTimeImmutable}> $windows
	 */
	private static function seniorWindowId(array $windows): string
	{
		$senior = $windows[0];
		foreach ($windows as $window)
		{
			if ((self::WINDOW_RANK[$window['id']] ?? 0) > (self::WINDOW_RANK[$senior['id']] ?? 0))
			{
				$senior = $window;
			}
		}

		return $senior['id'];
	}

	/**
	 * @param list<array{id: string, exhausted: bool, resetAt: ?\DateTimeImmutable}> $windows
	 */
	private static function windowResetAt(array $windows, ?string $windowId): ?\DateTimeImmutable
	{
		foreach ($windows as $window)
		{
			if ($window['id'] === $windowId)
			{
				return $window['resetAt'];
			}
		}

		return null;
	}

	/**
	 * @param array<?\DateTimeImmutable> $moments
	 */
	private static function latest(array $moments): ?\DateTimeImmutable
	{
		$latest = null;
		foreach ($moments as $moment)
		{
			if ($moment !== null && ($latest === null || $moment > $latest))
			{
				$latest = $moment;
			}
		}

		return $latest;
	}

	/**
	 * A window is exhausted when its status says so or it blocks requests; status unavailable
	 * means no data, not an empty window.
	 *
	 * @return list<array{id: string, exhausted: bool, resetAt: ?\DateTimeImmutable}>
	 */
	private static function parseWindows(mixed $windows): array
	{
		if ($windows === null)
		{
			return [];
		}

		if (!is_array($windows))
		{
			throw new \UnexpectedValueException('windows');
		}

		$parsed = [];
		foreach ($windows as $window)
		{
			$id = is_array($window) ? ($window['id'] ?? null) : null;
			$status = $window['status'] ?? null;
			$isBlocking = $window['isBlocking'] ?? null;
			if (
				!is_string($id)
				|| $id === ''
				|| !self::isOptionalString($status)
				|| ($isBlocking !== null && !is_bool($isBlocking))
			)
			{
				throw new \UnexpectedValueException('window');
			}

			$parsed[] = [
				'id' => $id,
				'exhausted' => $status === self::STATUS_EXHAUSTED || $isBlocking === true,
				'resetAt' => self::parseDateTime($window['resetAt'] ?? null),
			];
		}

		return $parsed;
	}

	private static function parseDateTime(mixed $value): ?\DateTimeImmutable
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value) || !preg_match(self::ISO_DATE_TIME, $value))
		{
			throw new \UnexpectedValueException('date time');
		}

		return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
	}

	private static function isOptionalString(mixed $value): bool
	{
		return $value === null || is_string($value);
	}
}
