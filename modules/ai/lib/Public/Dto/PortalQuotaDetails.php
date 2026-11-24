<?php

declare(strict_types=1);

namespace Bitrix\AI\Public\Dto;

/**
 * Limit details of the portal for the limit wall, read from data of AiLimitsService::getPortal.
 *
 * The window is the blocking one, with the latest resetAt when several block; its id is kept as
 * a string, so a window the code does not know yet still reaches the wall. The reset time is
 * always blockedUntil: without it the details are not given. A portal whose blocking window is
 * hidden by the platform gets the wall without a window but with the reset time.
 */
final class PortalQuotaDetails
{
	private const ISO_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

	private function __construct(
		/** day|week|month or an id unknown to the code; null when the window is hidden. */
		public readonly ?string $windowId,
		/** UTC. */
		public readonly \DateTimeImmutable $resetAt,
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
		$blockedUntil = self::parseDateTime($data['blockedUntil'] ?? null);
		$windowId = self::blockingWindowId(self::parseWindows($data['windows'] ?? null));

		if ($blockedUntil === null)
		{
			return QuotaDetailsUnavailableReason::NoResetAt;
		}

		return new self($windowId, $blockedUntil);
	}

	/**
	 * @param list<array{id: string, isBlocking: bool, resetAt: ?\DateTimeImmutable}> $windows
	 */
	private static function blockingWindowId(array $windows): ?string
	{
		$chosen = null;
		foreach ($windows as $window)
		{
			if (!$window['isBlocking'])
			{
				continue;
			}

			if (
				$chosen === null
				|| ($window['resetAt'] !== null && ($chosen['resetAt'] === null || $window['resetAt'] > $chosen['resetAt']))
			)
			{
				$chosen = $window;
			}
		}

		return $chosen['id'] ?? null;
	}

	/**
	 * Only a window that blocks requests counts; status unavailable means no data, not an empty
	 * window.
	 *
	 * @return list<array{id: string, isBlocking: bool, resetAt: ?\DateTimeImmutable}>
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
				|| ($status !== null && !is_string($status))
				|| ($isBlocking !== null && !is_bool($isBlocking))
			)
			{
				throw new \UnexpectedValueException('window');
			}

			$parsed[] = [
				'id' => $id,
				'isBlocking' => $isBlocking === true,
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
}
