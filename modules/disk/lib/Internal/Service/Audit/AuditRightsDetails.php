<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;

final class AuditRightsDetails
{
	private const MAX_RAW_SIZE = 65536;
	private const SCALAR_TYPES = [
		'oldTaskName' => 'string',
		'newTaskName' => 'string',
		'oldDomain' => 'string',
		'newDomain' => 'string',
		'oldNegative' => 'boolean',
		'newNegative' => 'boolean',
		'sourceGroupId' => 'integer',
		'targetGroupId' => 'integer',
	];

	public static function isSubjectEvent(string $eventCode): bool
	{
		return in_array($eventCode, [
			AuditEventCode::RightsSubjectAdded->value,
			AuditEventCode::RightsSubjectChanged->value,
			AuditEventCode::RightsSubjectRemoved->value,
		], true);
	}

	public static function validate(string $eventCode, array $details): void
	{
		$isSubjectEvent = self::isSubjectEvent($eventCode);
		$denylist = new AuditSecretDenylist();
		foreach ($details as $key => $value)
		{
			if (!is_string($key) || $key === '' || $denylist->isDenied($key))
			{
				throw new InvalidArgumentException('Audit details contain an invalid or secret key.');
			}

			if ($isSubjectEvent && in_array($key, ['oldRights', 'newRights'], true))
			{
				self::validateRightsList($value);
			}
			elseif ($value !== null && !is_scalar($value))
			{
				throw new InvalidArgumentException('Audit detail values must be scalar or null.');
			}
		}

		if (!$isSubjectEvent)
		{
			return;
		}

		if (!isset($details['accessCode']) || !is_string($details['accessCode']) || $details['accessCode'] === '')
		{
			throw new InvalidArgumentException('Audit rights accessCode is required.');
		}

		foreach (self::SCALAR_TYPES as $key => $type)
		{
			if (array_key_exists($key, $details) && gettype($details[$key]) !== $type)
			{
				throw new InvalidArgumentException('Audit rights detail has an invalid type.');
			}
		}

		if (array_key_exists('oldRights', $details) || array_key_exists('newRights', $details))
		{
			self::validateListSides($eventCode, $details);
		}
		else
		{
			self::validateScalarSides($eventCode, $details);
		}

		if (strlen(Json::encode($details)) > self::MAX_RAW_SIZE)
		{
			throw new InvalidArgumentException('Audit rights details exceed the size limit.');
		}
	}

	public static function validateRightsList(mixed $rights): void
	{
		if (!is_array($rights) || !array_is_list($rights))
		{
			throw new InvalidArgumentException('Audit rights must be a list.');
		}

		$previous = null;
		foreach ($rights as $right)
		{
			if (
				!is_array($right)
				|| count($right) !== 4
				|| !isset($right['domain'], $right['taskId'], $right['taskName'], $right['negative'])
				|| !is_string($right['domain'])
				|| !is_int($right['taskId'])
				|| !is_string($right['taskName'])
				|| !is_bool($right['negative'])
			)
			{
				throw new InvalidArgumentException('Audit rights rows must contain only typed rights fields.');
			}

			$order = [$right['domain'], $right['taskId'], $right['negative']];
			if ($previous !== null && self::compareRights($previous, $order) >= 0)
			{
				throw new InvalidArgumentException('Audit rights must be sorted and unique.');
			}
			$previous = $order;
		}
	}

	private static function compareRights(array $left, array $right): int
	{
		return strcmp($left[0], $right[0]) ?: ($left[1] <=> $right[1]) ?: ($left[2] <=> $right[2]);
	}

	private static function validateListSides(string $eventCode, array $details): void
	{
		if (!isset($details['oldRights'], $details['newRights']))
		{
			throw new InvalidArgumentException('Audit rights lists require both sides.');
		}

		foreach (['oldTaskName', 'newTaskName', 'oldDomain', 'newDomain', 'oldNegative', 'newNegative'] as $key)
		{
			if (array_key_exists($key, $details))
			{
				throw new InvalidArgumentException('Audit rights lists must not contain ambiguous scalar fields.');
			}
		}

		$oldCount = count($details['oldRights']);
		$newCount = count($details['newRights']);
		if (
			max($oldCount, $newCount) < 2
			|| ($eventCode === AuditEventCode::RightsSubjectAdded->value && ($oldCount !== 0 || $newCount === 0))
			|| ($eventCode === AuditEventCode::RightsSubjectRemoved->value && ($oldCount === 0 || $newCount !== 0))
			|| ($eventCode === AuditEventCode::RightsSubjectChanged->value && ($oldCount === 0 || $newCount === 0))
		)
		{
			throw new InvalidArgumentException('Audit rights lists do not match the event sides.');
		}
	}

	private static function validateScalarSides(string $eventCode, array $details): void
	{
		foreach (['old', 'new'] as $side)
		{
			$required = $side === 'old'
				? $eventCode !== AuditEventCode::RightsSubjectAdded->value
				: $eventCode !== AuditEventCode::RightsSubjectRemoved->value
			;
			$hasName = array_key_exists($side . 'TaskName', $details);
			$hasDomain = array_key_exists($side . 'Domain', $details);
			$hasNegative = array_key_exists($side . 'Negative', $details);
			if ($hasName !== $required || $hasDomain !== $hasNegative || (!$required && $hasDomain))
			{
				throw new InvalidArgumentException('Audit rights scalar fields do not match the event sides.');
			}
		}
	}
}
