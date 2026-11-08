<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Security\Random;

final class StateRepository
{
	public const MODULE_ID = 'biconnector';
	public const OPTION_NAME = '~portal_healthcheck_state';
	private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	public function __construct(
		private readonly StateLock $lock = new StateLock(),
	)
	{
	}

	public function reserve(string $hash, \DateTimeImmutable $issuedAt, \DateTimeImmutable $expiresAt): array
	{
		return $this->mutate(function (array &$state) use ($hash, $issuedAt, $expiresAt): array {
			$this->prune($state, $issuedAt);
			$blockedUntil = $state['pending']['expiresAt'] ?? $state['nextAllowedAt'] ?? null;
			if ($blockedUntil !== null && $this->date($blockedUntil) > $issuedAt)
			{
				throw new \DomainException($blockedUntil, 429);
			}

			$id = Random::getString(32, true);
			$state['pending'] = [
				'id' => $id,
				'hash' => $hash,
				'issuedAt' => $this->format($issuedAt),
				'expiresAt' => $this->format($expiresAt),
			];
			$state['unresolved'] = null;

			return ['id' => $id, 'expiresAt' => $expiresAt];
		});
	}

	public function reconcile(string $reservationId, ProxyStartReply $reply): Snapshot
	{
		return $this->mutate(function (array &$state) use ($reservationId, $reply): Snapshot {
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$this->prune($state, $now);
			$pending = $state['pending'] ?? null;
			if ($pending === null || $pending['id'] !== $reservationId)
			{
				return $this->snapshot($state, $now);
			}

			if (isset($pending['callback']))
			{
				$state['pending'] = null;
				if ($reply->httpStatus === 429
					|| ($reply->diagnosticId !== null
					&& $reply->diagnosticId !== $pending['callback']['diagnosticId'])
				)
				{
					$state['lastResult']['errorCode'] = 'HEALTHCHECK_PROTOCOL_ERROR';
				}
				elseif ($reply->nextAllowedAt !== null)
				{
					$state['nextAllowedAt'] = $this->format($reply->nextAllowedAt);
				}

				return $this->snapshot($state, $now);
			}

			if ($reply->requestNotSent)
			{
				$state['pending'] = null;
				$state['unresolved'] = null;
				if (isset($state['nextAllowedAt']) && $this->date($state['nextAllowedAt']) <= $now)
				{
					$state['nextAllowedAt'] = null;
				}
				$state['lastResult'] = [
					'status' => 'FAILED',
					'errorCode' => $reply->errorCode,
				];

				return $this->snapshot($state, $now);
			}

			if ($reply->httpStatus === 500 || $reply->httpStatus === 0)
			{
				$state['pending']['errorCode'] = $reply->errorCode;
				return $this->snapshot($state, $now);
			}
			$mayReceiveCallback = $reply->httpStatus === 503
				&& in_array($reply->errorCode, [
					'HEALTHCHECK_CONTROLLER_UNAVAILABLE',
					'HEALTHCHECK_QUEUE_FULL',
				], true);
			if ($mayReceiveCallback && (
				$reply->diagnosticId === null
				|| $reply->proxy === null
				|| $reply->nextAllowedAt === null
			))
			{
				$state['pending']['errorCode'] = $reply->errorCode;
				return $this->snapshot($state, $now);
			}

			$state['pending'] = null;
			$complete = $reply->diagnosticId !== null
				&& $reply->proxy !== null
				&& $reply->nextAllowedAt !== null;
			$accepted = $reply->httpStatus === 200
				&& $complete
				&& in_array($reply->status, ['RUNNING', 'PORTAL_UNAVAILABLE', 'NODES_CHECK_REJECTED'], true)
				&& ($reply->status !== 'RUNNING' || $reply->resultDeadline !== null)
				&& ($reply->status !== 'NODES_CHECK_REJECTED' || $reply->nodesCheck !== null);
			$accepted = $accepted || (
				$reply->httpStatus === 503
				&& $complete
			);
			if (!$accepted)
			{
				if ($reply->nextAllowedAt !== null)
				{
					$state['nextAllowedAt'] = $this->format($reply->nextAllowedAt);
				}

				$data = $this->snapshot($state, $now)->toArray();
				$data['errorCode'] = $reply->errorCode;
				$data['retryAfter'] = $reply->retryAfter;

				return new Snapshot(array_filter($data, static fn(mixed $value): bool => $value !== null));
			}

			$nextAllowedAt = $reply->nextAllowedAt ?? $this->date($pending['issuedAt'])->modify('+30 minutes');
			$state['nextAllowedAt'] = $this->format($nextAllowedAt);
			$state['active'] = ($reply->status === 'RUNNING' || $mayReceiveCallback) ? [
				'hash' => $pending['hash'],
				'expiresAt' => $pending['expiresAt'],
				'diagnosticId' => $reply->diagnosticId,
			] : null;
			$state['lastResult'] = [
				'status' => $reply->status === 'RUNNING' ? 'RUNNING' : ($reply->status ?? 'FAILED'),
				'diagnosticId' => $reply->diagnosticId,
				'proxy' => $reply->proxy,
				'nodesCheck' => $reply->nodesCheck,
				'errorCode' => $reply->errorCode,
				'retryAfter' => $reply->retryAfter,
				'resultDeadline' => $mayReceiveCallback
					? $pending['expiresAt']
					: ($reply->resultDeadline === null ? null : $this->format($reply->resultDeadline)),
			];
			$state['unresolved'] = null;

			return $this->snapshot($state, $now);
		});
	}

	public function accept(string $tokenHash, string $diagnosticId, array $result): string
	{
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($this->findAttempt($this->read()['state'], $tokenHash, $diagnosticId, $now) === null)
		{
			return 'forbidden';
		}

		return $this->mutate(function (array &$state) use ($tokenHash, $diagnosticId, $result): string {
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$attempt = $this->findAttempt($state, $tokenHash, $diagnosticId, $now);
			if ($attempt === null)
			{
				return 'forbidden';
			}

			$this->prune($state, $now);
			$pending = $state['pending'] ?? null;
			$isPending = $attempt === 'pending';
			if (($state['lastResult']['status'] ?? null) === 'COMPLETED'
				&& ($state['lastResult']['diagnosticId'] ?? null) === $diagnosticId)
			{
				return 'duplicate';
			}

			if ($isPending)
			{
				$state['pending']['callback'] = ['diagnosticId' => $diagnosticId];
				$state['active'] = [
					'hash' => $pending['hash'],
					'expiresAt' => $pending['expiresAt'],
					'diagnosticId' => $diagnosticId,
				];
				$state['nextAllowedAt'] = $this->format($this->date($pending['issuedAt'])->modify('+30 minutes'));
			}
			$state['lastResult'] = array_merge($result, [
				'status' => 'COMPLETED',
				'diagnosticId' => $diagnosticId,
			]);
			$state['unresolved'] = null;

			return 'accepted';
		});
	}

	public function getSnapshot(\DateTimeImmutable $now): Snapshot
	{
		return $this->snapshot($this->read()['state'], $now);
	}

	/**
	 * Returns the attempt that the token authenticates: 'pending', 'active' or null.
	 */
	private function findAttempt(array $state, string $tokenHash, string $diagnosticId, \DateTimeImmutable $now): ?string
	{
		$pending = $state['pending'] ?? null;
		if ($pending !== null
			&& $this->date($pending['expiresAt']) > $now
			&& hash_equals($pending['hash'], $tokenHash))
		{
			$knownId = $pending['callback']['diagnosticId'] ?? null;

			return ($knownId === null || $knownId === $diagnosticId) ? 'pending' : null;
		}

		$active = $state['active'] ?? null;
		if ($active !== null
			&& $this->date($active['expiresAt']) > $now
			&& hash_equals($active['hash'], $tokenHash))
		{
			$knownId = $active['diagnosticId'] ?? null;

			return ($knownId === null || $knownId === $diagnosticId) ? 'active' : null;
		}

		return null;
	}

	private function mutate(callable $operation): mixed
	{
		if (!$this->lock->lock())
		{
			throw new \RuntimeException('Cannot lock portal healthcheck state');
		}

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$stored = $this->read();
			$state = $stored['state'];
			$value = $operation($state);
			if ($state !== $stored['state'])
			{
				Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode(
					['revision' => $stored['revision'] + 1, 'state' => $state],
					JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
				));
			}

			return $value;
		}
		finally
		{
			$pool->useMasterOnly(false);
			$this->lock->unlock();
		}
	}

	/**
	 * Reads the stored value from the database, bypassing the option cache that may be stale.
	 *
	 * @return array{revision: int, state: array}
	 */
	private function read(): array
	{
		$sql = (new SqlExpression(
			'SELECT VALUE FROM ?# WHERE MODULE_ID = ?s AND NAME = ?s',
			'b_option',
			self::MODULE_ID,
			self::OPTION_NAME,
		))->compile();
		$value = Application::getConnection()->queryScalar($sql);
		if ($value === null || $value === false || $value === '')
		{
			return ['revision' => 0, 'state' => []];
		}

		$stored = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($stored) || !is_int($stored['revision'] ?? null) || !is_array($stored['state'] ?? null))
		{
			throw new \RuntimeException('Invalid portal healthcheck state');
		}

		return $stored;
	}

	private function prune(array &$state, \DateTimeImmutable $now): void
	{
		foreach (['pending', 'active'] as $key)
		{
			if (isset($state[$key]) && $this->date($state[$key]['expiresAt']) <= $now)
			{
				if ($key === 'pending' && !isset($state['pending']['callback']))
				{
					$state['unresolved'] = [
						'expiresAt' => $state['pending']['expiresAt'],
						'errorCode' => $state['pending']['errorCode'] ?? null,
					];
				}
				$state[$key] = null;
			}
		}
	}

	private function snapshot(array $state, \DateTimeImmutable $now): Snapshot
	{
		$pending = $state['pending'] ?? null;
		if ($pending !== null && !isset($pending['callback']))
		{
			$expiresAt = $this->date($pending['expiresAt']);
			if ($expiresAt > $now)
			{
				$nextAllowedAt = $state['nextAllowedAt'] ?? null;
				if ($nextAllowedAt === null || $this->date($nextAllowedAt) < $expiresAt)
				{
					$nextAllowedAt = $pending['expiresAt'];
				}

				return new Snapshot(array_filter([
					'status' => 'PENDING',
					'nextAllowedAt' => $nextAllowedAt,
					'resultDeadline' => $pending['expiresAt'],
					'errorCode' => $pending['errorCode'] ?? null,
				], static fn(mixed $value): bool => $value !== null));
			}

			return new Snapshot([
				'status' => 'RESULT_MISSING',
				'nextAllowedAt' => $state['nextAllowedAt'] ?? $pending['expiresAt'],
				'errorCode' => $pending['errorCode'] ?? 'HEALTHCHECK_RESULT_TIMEOUT',
				'nodes' => [],
			]);
		}
		if (isset($state['unresolved']))
		{
			return new Snapshot([
				'status' => 'RESULT_MISSING',
				'nextAllowedAt' => $state['nextAllowedAt'] ?? $state['unresolved']['expiresAt'],
				'errorCode' => $state['unresolved']['errorCode'] ?? 'HEALTHCHECK_RESULT_TIMEOUT',
				'nodes' => [],
			]);
		}
		$result = $state['lastResult'] ?? [];
		if (($result['status'] ?? null) === 'RUNNING'
			&& isset($result['resultDeadline'])
			&& $this->date($result['resultDeadline']) <= $now)
		{
			$result['status'] = 'RESULT_MISSING';
			$result['errorCode'] = 'HEALTHCHECK_RESULT_TIMEOUT';
		}
		$result['status'] ??= 'IDLE';
		$result['nodes'] ??= [];
		$result['nextAllowedAt'] = $state['nextAllowedAt'] ?? null;

		return new Snapshot(array_filter($result, static fn(mixed $value): bool => $value !== null));
	}

	private function format(\DateTimeImmutable $date): string
	{
		return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT);
	}

	private function date(string $date): \DateTimeImmutable
	{
		return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
	}
}
