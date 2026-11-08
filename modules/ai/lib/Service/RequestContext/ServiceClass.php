<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

/**
 * Service class of a request in both representations at once.
 *
 * The two representations come from a single computation on purpose: the string travels
 * inside the signed context, the number goes into the request body, and a receiver that
 * sees them disagree has no way to tell which one to trust.
 *
 * The number is allowed to be absent. While it is, no send point puts `priority` into the
 * body and the receiver keeps reading a missing field as zero — that absence is the switch
 * that turns body filling on and off without a release.
 */
final class ServiceClass
{
	public const OPTION_VALUES = 'cuet_service_class';

	private const CLAIM_MAX_LENGTH = 64;
	private const PRIORITY_MIN = 0;
	private const PRIORITY_MAX = 9;

	private const DEFAULTS = [
		'interactive' => 'high',
		'agent' => 'base',
		'background' => 'bulk',
	];

	private function __construct(
		public readonly string $claim,
		public readonly ?int $priority,
	)
	{
	}

	/**
	 * Resolves the service class of a call type: option first, built-in defaults otherwise.
	 *
	 * An unusable option is not a send failure — defaults apply and the fact is logged.
	 */
	public static function forCallType(CallType $callType): self
	{
		$configured = self::readOption()[$callType->value] ?? null;
		$claim = self::DEFAULTS[$callType->value];
		$priority = null;

		if (is_array($configured))
		{
			$claim = self::readClaim($configured, $claim);
			$priority = self::readPriority($configured);
		}
		elseif ($configured !== null)
		{
			self::logMalformedOption('call type entry is not an object');
		}

		return new self($claim, $priority);
	}

	/**
	 * The built-in class of a call type, with the option left unread.
	 *
	 * This is the answer for a caller that has already failed once: reading the option is
	 * itself one of the things that can fail, so the fallback must not depend on it.
	 */
	public static function builtInFor(CallType $callType): self
	{
		return new self(self::DEFAULTS[$callType->value], null);
	}

	private static function readOption(): array
	{
		$raw = Option::get('ai', self::OPTION_VALUES, '');
		if (!is_string($raw) || $raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			self::logMalformedOption('option is not valid JSON');

			return [];
		}

		if (!is_array($decoded))
		{
			self::logMalformedOption('option is not an object');

			return [];
		}

		// An unknown call type is not a harmless extra key: it usually means a typo in the
		// name of a real one, and the real one then silently keeps the built-in class.
		$unknown = array_diff(array_keys($decoded), array_keys(self::DEFAULTS));
		if ($unknown !== [])
		{
			self::logMalformedOption('unknown call types ignored: ' . implode(', ', $unknown));
		}

		return $decoded;
	}

	private static function readClaim(array $configured, string $fallback): string
	{
		$claim = $configured['claim'] ?? null;
		if ($claim === null)
		{
			return $fallback;
		}

		if (is_string($claim) && preg_match('/^[A-Za-z0-9._-]{1,' . self::CLAIM_MAX_LENGTH . '}$/', $claim) === 1)
		{
			return $claim;
		}

		self::logMalformedOption('claim is not a well-formed label');

		return $fallback;
	}

	private static function readPriority(array $configured): ?int
	{
		$priority = $configured['priority'] ?? null;
		if ($priority === null)
		{
			return null;
		}

		if (is_int($priority) && $priority >= self::PRIORITY_MIN && $priority <= self::PRIORITY_MAX)
		{
			return $priority;
		}

		self::logMalformedOption('priority is out of the 0..9 range');

		return null;
	}

	private static function logMalformedOption(string $reason): void
	{
		ContextHeaders::log('warning', 'service class option ignored: ' . $reason);
	}
}
