<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Pull\Feature\PushScheduleFeature;
use Bitrix\Pull\Push\DeliveryContext;
use Bitrix\Pull\Push\DeliveryContextFactory;

final class DeliveryPolicyEvaluator
{
	public const DELIVER = 'deliver';
	public const SUPPRESS = 'suppress';
	private const WORK_STATUS_OPENED = 'opened';
	private const WORK_STATUS_PAUSED = 'paused';
	private const WORK_STATUS_CLOSED = 'closed';
	private const WORK_STATUS_NONE = 'none';
	private const SCHEDULE_TYPE_FIXED = 'fixed';
	private const SCHEDULE_TYPE_SHIFT = 'shift';
	private const SCHEDULE_TYPE_FLEX = 'flex';
	private const SCHEDULE_TYPE_UNKNOWN = 'unknown';
	private const AUTOMATIC_MODE_SCHEDULE = 'schedule';
	private const AUTOMATIC_MODE_TIME_TRACKING = 'time_tracking';
	private const TIME_ZONE_CONTEXT_MAX_AGE = 7 * 86400;

	private readonly \Closure $featureResolver;
	private readonly \Closure $readinessResolver;

	public function __construct(
		?\Closure $featureResolver = null,
		private readonly ?PolicyMetrics $metrics = null,
		?\Closure $readinessResolver = null,
	)
	{
		$this->readinessResolver = $readinessResolver ?? (new ReadinessService())->isReady(...);
		$this->featureResolver = $featureResolver
			?? static fn (int $userId): bool => Feature::isEnabled(
				PushScheduleFeature::class,
				new Context($userId),
			);
	}

	public function evaluate(
		int $userId,
		mixed $context,
		array $state,
		?string $timeZoneId,
		int $serverInstant,
		?bool $enforcementEnabled = null,
		?array $timeZoneContext = null,
	): string
	{
		$startedAt = hrtime(true);
		$normalizedContext = $this->restoreContext($context);
		$candidate = $this->evaluateCandidate(
			$normalizedContext,
			$state,
			$timeZoneId,
			$serverInstant,
			$timeZoneContext,
		);
		[$unknownSource, $unknownDestination] = $this->getUnknownContextParts(
			$context,
			$normalizedContext,
			$state,
		);
		$decision = $candidate === self::SUPPRESS
			&& ($enforcementEnabled ?? $this->isFeatureEnabled($userId))
			&& $this->isReady()
			? self::SUPPRESS
			: self::DELIVER;
		$this->recordEvaluation(
			$candidate,
			$decision,
			$unknownSource,
			$unknownDestination,
			$this->getTrustedTimeZone($timeZoneId, $serverInstant, $timeZoneContext) === null,
			(int)((hrtime(true) - $startedAt) / 1000),
		);

		return $decision;
	}

	private function isReady(): bool
	{
		try
		{
			return (bool)($this->readinessResolver)();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function evaluateCandidate(
		mixed $context,
		array $state,
		?string $timeZoneId,
		int $serverInstant,
		?array $timeZoneContext = null,
	): string
	{
		$context = $this->restoreContext($context);
		if ($context === null || !$this->isPolicyStateTrusted($state))
		{
			return self::DELIVER;
		}
		if ($this->isServiceBypass($context))
		{
			return self::DELIVER;
		}

		$policy = $state['policy'];
		$activeRules = array_values(array_filter(
			$policy['rules'] ?? [],
			static fn (mixed $rule): bool => is_array($rule) && ($rule['active'] ?? false),
		));
		$automaticEnabled = (bool)($policy['automaticEnabled'] ?? false);
		if (!($policy['exists'] ?? false) || ($activeRules === [] && !$automaticEnabled))
		{
			return self::DELIVER;
		}

		$sourceId = $context->destination === DeliveryContext::DESTINATION_INCOMING_CALL
			&& $context->sourceId !== 'telephony'
				? 'unknown'
				: $context->sourceId;
		if (
			$sourceId !== 'unknown'
			&& isset($state['exceptionSourceIdSet'][$sourceId])
			&& isset($state['registeredSourceIds'][$sourceId])
		)
		{
			return self::DELIVER;
		}

		$timeZone = $this->getTrustedTimeZone($timeZoneId, $serverInstant, $timeZoneContext);
		if ($timeZone === null)
		{
			return self::DELIVER;
		}

		$manualSilent = $this->matchesAnyRule($activeRules, $timeZone, $serverInstant);
		if (!$this->isAutomaticStateTrusted($automaticEnabled, $state))
		{
			return $this->resolveSilentDecision($manualSilent);
		}

		$automaticSilent = false;
		if ($automaticEnabled && ($state['automaticConfigured'] ?? false))
		{
			$nonWorking = $this->resolveNonWorking($state, $serverInstant);
			if ($nonWorking !== null)
			{
				$automaticSilent = $nonWorking || ($state['hasBlockingAbsence'] ?? false);
			}
		}

		return $this->resolveSilentDecision($manualSilent || $automaticSilent);
	}

	private function isAutomaticStateTrusted(bool $automaticEnabled, array $state): bool
	{
		if (!$automaticEnabled)
		{
			return true;
		}
		if (!($state['automaticConfigurationTrusted'] ?? false))
		{
			return false;
		}
		if (!($state['automaticConfigured'] ?? false))
		{
			return true;
		}

		return ($state['workStateTrusted'] ?? false) && ($state['absenceStateTrusted'] ?? false);
	}

	private function resolveSilentDecision(bool $silent): string
	{
		return $silent ? self::SUPPRESS : self::DELIVER;
	}

	private function isFeatureEnabled(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			return (bool)($this->featureResolver)($userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function recordEvaluation(
		string $candidate,
		string $decision,
		bool $unknownSource,
		bool $unknownDestination,
		bool $invalidTimeZone,
		int $durationMicroseconds,
	): void
	{
		try
		{
			$this->metrics?->recordEvaluation(
				$candidate,
				$decision,
				$unknownSource,
				$unknownDestination,
				$invalidTimeZone,
				$durationMicroseconds,
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function getUnknownContextParts(
		mixed $originalContext,
		?DeliveryContext $context,
		array $state,
	): array
	{
		if ($context === null)
		{
			return [true, true];
		}

		if ($originalContext instanceof DeliveryContext)
		{
			$destination = $originalContext->destination;
		}
		else
		{
			$destination = (string)($originalContext['destination'] ?? '');
		}

		return [
			$context->sourceId === 'unknown'
				|| !isset($state['registeredSourceIds'][$context->sourceId]),
			!in_array($destination, [
				DeliveryContext::DESTINATION_USER,
				DeliveryContext::DESTINATION_INCOMING_CALL,
				DeliveryContext::DESTINATION_CONTROL,
				DeliveryContext::DESTINATION_BADGE,
				DeliveryContext::DESTINATION_BACKGROUND,
			], true),
		];
	}

	private function restoreContext(mixed $context): ?DeliveryContext
	{
		if ($context instanceof DeliveryContext)
		{
			return $this->normalizeIncomingCall($context);
		}
		if (!is_array($context) || ($context['version'] ?? null) !== DeliveryContext::VERSION)
		{
			return null;
		}

		try
		{
			$restored = (new DeliveryContextFactory())->restore($context);
			if (
				($context['destination'] ?? null) === DeliveryContext::DESTINATION_INCOMING_CALL
				&& ($context['sourceId'] ?? null) !== 'telephony'
			)
			{
				return $this->normalizeIncomingCall($restored, true);
			}

			return $this->normalizeIncomingCall($restored);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function normalizeIncomingCall(DeliveryContext $context, bool $forceUnknown = false): DeliveryContext
	{
		if (
			!$forceUnknown
			&& (
				$context->destination !== DeliveryContext::DESTINATION_INCOMING_CALL
				|| $context->sourceId === 'telephony'
			)
		)
		{
			return $context;
		}

		return new DeliveryContext(
			'unknown',
			$context->producerModuleId,
			$context->eventType,
			DeliveryContext::DESTINATION_USER,
			DeliveryContext::OPERATION_NOTIFY,
			$context->hasUserContent,
			$context->playsSound,
			$context->opensUserAction,
		);
	}

	private function isPolicyStateTrusted(array $state): bool
	{
		return ($state['policyTrusted'] ?? false) && is_array($state['policy'] ?? null);
	}

	private function isServiceBypass(DeliveryContext $context): bool
	{
		if ($context->hasUserContent || $context->playsSound || $context->opensUserAction)
		{
			return false;
		}

		return match ($context->destination)
		{
			DeliveryContext::DESTINATION_CONTROL => in_array(
				$context->operation,
				[DeliveryContext::OPERATION_NOTIFICATION_REMOVE, DeliveryContext::OPERATION_CALL_END],
				true,
			),
			DeliveryContext::DESTINATION_BADGE => $context->operation === DeliveryContext::OPERATION_COUNTER_UPDATE,
			DeliveryContext::DESTINATION_BACKGROUND => $context->operation === DeliveryContext::OPERATION_CONTENTLESS_SYNC,
			default => false,
		};
	}

	private function getTimeZone(?string $timeZoneId): ?\DateTimeZone
	{
		if ($timeZoneId === null || trim($timeZoneId) === '')
		{
			return null;
		}

		try
		{
			return new \DateTimeZone(trim($timeZoneId));
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getTrustedTimeZone(
		?string $timeZoneId,
		int $serverInstant,
		?array $timeZoneContext,
	): ?\DateTimeZone
	{
		$timeZone = $this->getTimeZone($timeZoneId);
		if ($timeZone === null || $timeZoneContext === null)
		{
			return null;
		}

		$offsetMinutes = $timeZoneContext['offsetMinutes'] ?? null;
		$receivedAt = $timeZoneContext['receivedAt'] ?? null;
		if (!is_int($offsetMinutes) || $offsetMinutes < -840 || $offsetMinutes > 840)
		{
			return null;
		}

		$receivedAtTimestamp = $this->getTimestamp($receivedAt);
		if (
			$receivedAtTimestamp === null
			|| $receivedAtTimestamp > $serverInstant
			|| $serverInstant - $receivedAtTimestamp > self::TIME_ZONE_CONTEXT_MAX_AGE
		)
		{
			return null;
		}

		$receivedInstant = new \DateTimeImmutable('@' . $receivedAtTimestamp);

		return (int)($timeZone->getOffset($receivedInstant) / 60) === $offsetMinutes
			? $timeZone
			: null;
	}

	private function getTimestamp(mixed $value): ?int
	{
		if ($value instanceof \DateTimeInterface)
		{
			return $value->getTimestamp();
		}
		if (is_object($value) && method_exists($value, 'getTimestamp'))
		{
			$timestamp = $value->getTimestamp();

			return is_int($timestamp) ? $timestamp : null;
		}

		return null;
	}

	private function matchesAnyRule(array $rules, \DateTimeZone $timeZone, int $serverInstant): bool
	{
		$local = (new \DateTimeImmutable('@' . $serverInstant))->setTimezone($timeZone);
		$day = (int)$local->format('N');
		$minute = (int)$local->format('G') * 60 + (int)$local->format('i');
		$previousDay = $day === 1 ? 7 : $day - 1;

		foreach ($rules as $rule)
		{
			$daysMask = (int)($rule['daysMask'] ?? 0);
			$startMinute = (int)($rule['startMinute'] ?? -1);
			$endMinute = (int)($rule['endMinute'] ?? -1);
			if (
				$daysMask < 1
				|| $daysMask > 127
				|| $startMinute < 0
				|| $startMinute > 1439
				|| $endMinute < 0
				|| $endMinute > 1439
				|| $startMinute === $endMinute
			)
			{
				continue;
			}

			if ($startMinute < $endMinute)
			{
				if (
					$this->hasDay($daysMask, $day)
					&& $startMinute <= $minute
					&& $minute < $endMinute
				)
				{
					return true;
				}
			}
			elseif (
				($this->hasDay($daysMask, $day) && $minute >= $startMinute)
				|| ($this->hasDay($daysMask, $previousDay) && $minute < $endMinute)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function hasDay(int $daysMask, int $day): bool
	{
		return ($daysMask & (1 << ($day - 1))) !== 0;
	}

	private function resolveNonWorking(array $state, int $serverInstant): ?bool
	{
		$status = (string)($state['workStatus'] ?? '');
		if (in_array($status, [self::WORK_STATUS_CLOSED, self::WORK_STATUS_NONE], true))
		{
			return true;
		}
		if (!in_array($status, [self::WORK_STATUS_OPENED, self::WORK_STATUS_PAUSED], true))
		{
			return null;
		}

		$automaticMode = (string)($state['automaticMode'] ?? '');
		$scheduleType = (string)($state['scheduleType'] ?? '');
		if ($automaticMode === self::AUTOMATIC_MODE_TIME_TRACKING)
		{
			return in_array($scheduleType, [self::SCHEDULE_TYPE_FLEX, self::SCHEDULE_TYPE_UNKNOWN], true)
				? false
				: null;
		}
		if (
			$automaticMode !== self::AUTOMATIC_MODE_SCHEDULE
			|| !in_array($scheduleType, [self::SCHEDULE_TYPE_FIXED, self::SCHEDULE_TYPE_SHIFT], true)
		)
		{
			return null;
		}

		$startTimestamp = $state['startTimestamp'] ?? null;
		$stopTimestamp = $state['stopTimestamp'] ?? null;
		if (!is_int($startTimestamp) || $startTimestamp <= 0)
		{
			return null;
		}
		if ($stopTimestamp !== null && (!is_int($stopTimestamp) || $stopTimestamp <= $startTimestamp))
		{
			return null;
		}
		if ($serverInstant < $startTimestamp)
		{
			return true;
		}

		return $stopTimestamp !== null && $serverInstant >= $stopTimestamp;
	}
}
