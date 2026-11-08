<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class WebhookEventPolicy
{
	public const FINALIZATION_SEPARATE = 'separate_event';
	public const FINALIZATION_THIS_EVENT = 'this_event';
	public const FINALIZATION_NONE = 'not_applicable';
	public const FINALIZATION_LEGACY = 'legacy';
	private const CLOSED_FIELDS = [
		'rev', 'reason', 'origin_unknown', 'terminated_sessions', 'retained_sessions', 'session_finalization',
	];
	private const MAX_SESSIONS = 2000;

	/**
	 * Failure codes that describe the delivery itself and not the state of the portal: the same
	 * body decoded again ends the same way, so the platform must stop redelivering it.
	 */
	private const PERMANENT_CODES = [
		'validation',
		'ssrf_blocked',
		'vibeoffice_file_too_large',
		'vibeoffice_lock_order',
		'vibeoffice_registry_failed',
	];

	/** True when no retry of this delivery can end differently. */
	public static function isPermanentFailure(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return false;
		}
		foreach ($result->getErrors() as $error)
		{
			if (!in_array((string)$error->getCode(), self::PERMANENT_CODES, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The author a saved payload claims for the new version.
	 *
	 * `users[0]` is honoured ONLY when it belongs to a participant of this document; otherwise the
	 * payload could spoof authorship in the version history and the caller's fallback wins. The
	 * participants are iterated lazily, so a payload without a claim costs no lookup.
	 *
	 * @param iterable<int> $participantUserIds
	 */
	public static function resolveVersionAuthorId(array $payload, iterable $participantUserIds, int $fallbackId): int
	{
		$users = $payload['users'] ?? null;
		$candidate = is_array($users) && isset($users[0]) ? (int)$users[0] : 0;
		if ($candidate <= 0)
		{
			return $fallbackId;
		}
		foreach ($participantUserIds as $userId)
		{
			if ((int)$userId === $candidate)
			{
				return $candidate;
			}
		}

		return $fallbackId;
	}

	public function classify(string $event, array $payload): Result
	{
		$key = $payload['host_doc_key'] ?? null;
		if (!is_string($key) || $key === '' || strlen($key) > 128)
		{
			return $this->invalid('Invalid host_doc_key.');
		}
		if (array_key_exists('origin_unknown', $payload) && !is_bool($payload['origin_unknown']))
		{
			return $this->invalid('Invalid origin_unknown.');
		}

		$legacy = match ($event)
		{
			WebhookProcessor::EVENT_SESSION_CLOSED => !$this->hasAny($payload, self::CLOSED_FIELDS),
			WebhookProcessor::EVENT_SAVED => !$this->hasAny($payload, ['session_finalization', 'origin_unknown']),
			default => !array_key_exists('origin_unknown', $payload),
		};
		$editorKey = $payload['editor_key'] ?? null;
		$rev = $payload['rev'] ?? null;
		if ($editorKey !== null && (!is_string($editorKey) || strlen($editorKey) > 255))
		{
			return $this->invalid('Invalid editor_key.');
		}
		if ($rev !== null && (!is_int($rev) || $rev < 0 || $rev > 2_147_483_647))
		{
			return $this->invalid('Invalid revision.');
		}

		$data = [
			'hostDocKey' => $key,
			'legacy' => $legacy,
			'identityKnown' => !($payload['origin_unknown'] ?? false) && $editorKey !== null
				&& $editorKey !== '' && $rev !== null,
			'editorKey' => $editorKey,
			'rev' => $rev,
			'snapshotSeq' => null,
			'finalization' => self::FINALIZATION_NONE,
		];

		return match ($event)
		{
			WebhookProcessor::EVENT_SAVED => $this->saved($payload, $data),
			WebhookProcessor::EVENT_SESSION_CLOSED => $this->closed($payload, $data),
			default => (new Result())->setData($data),
		};
	}

	private function saved(array $payload, array $data): Result
	{
		$source = $payload['source'] ?? 'save';
		if (!is_string($source))
		{
			return $this->invalid('Invalid saved source.');
		}
		$source = strtolower($source);
		$seq = $payload['snapshot_seq'] ?? null;
		if ($seq !== null && (!is_int($seq) || $seq <= 0))
		{
			return $this->invalid('Invalid snapshot sequence.');
		}
		$data['source'] = $source;
		$data['snapshotSeq'] = $seq;
		if ($data['legacy'])
		{
			$data['finalization'] = in_array($source, ['save', 'forgotten'], true)
				? self::FINALIZATION_LEGACY : self::FINALIZATION_NONE;
		}
		else
		{
			$finalization = $payload['session_finalization'] ?? null;
			if (!in_array($finalization, [
				self::FINALIZATION_SEPARATE,
				self::FINALIZATION_THIS_EVENT,
				self::FINALIZATION_NONE,
			], true))
			{
				return $this->invalid('Invalid session_finalization.');
			}
			$data['finalization'] = $finalization;
			$data['identityKnown'] = $data['identityKnown'] && $seq !== null;
		}

		return (new Result())->setData($data);
	}

	private function closed(array $payload, array $data): Result
	{
		$data['terminated'] = [];
		$data['retained'] = [];
		if ($data['legacy'])
		{
			return (new Result())->setData($data);
		}

		foreach (['terminated_sessions' => 'terminated', 'retained_sessions' => 'retained'] as $field => $target)
		{
			if (!array_key_exists($field, $payload) || $payload[$field] === null)
			{
				$data['identityKnown'] = false;
				continue;
			}
			$uuids = $this->uuids($payload[$field]);
			if ($uuids === null)
			{
				return $this->invalid('Invalid closed session list.');
			}
			$data[$target] = $uuids;
		}
		if (array_intersect($data['terminated'], $data['retained']))
		{
			return $this->invalid('Closed session lists overlap.');
		}

		return (new Result())->setData($data);
	}

	private function uuids(mixed $values): ?array
	{
		if (!is_array($values) || !array_is_list($values) || count($values) > self::MAX_SESSIONS)
		{
			return null;
		}
		$result = [];
		foreach ($values as $uuid)
		{
			if (!is_string($uuid)
				|| preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid) !== 1)
			{
				return null;
			}
			$uuid = strtolower($uuid);
			$result[$uuid] = $uuid;
		}

		return array_values($result);
	}

	private function hasAny(array $payload, array $keys): bool
	{
		foreach ($keys as $key)
		{
			if (array_key_exists($key, $payload))
			{
				return true;
			}
		}

		return false;
	}

	private function invalid(string $message): Result
	{
		return (new Result())->addError(new Error($message, 'validation'));
	}
}
