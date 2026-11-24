<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use RuntimeException;
use Throwable;

final class SignaturePolicyRepository
{
	public const BINDING_OPTION = 'disk_vibeoffice_webhook_binding';
	public const NONE_PREFIX = 'none:';

	public static function noneBinding(int $receiveMin): string
	{
		if (!in_array($receiveMin, [1, 2], true))
		{
			throw new RuntimeException('The webhook signature minimum is invalid.');
		}

		return self::NONE_PREFIX . $receiveMin;
	}

	public function getCurrent(): ?array
	{
		return $this->primary(fn(): ?array => $this->readCurrent(false));
	}

	public function currentPointer(): array
	{
		return $this->primary(function (): array {
			$row = $this->readCurrent(false, $receiveMin);

			return ['bindingId' => $row['BINDING_ID'] ?? null, 'receiveMin' => (int)($row['RECEIVE_MIN'] ?? $receiveMin)];
		});
	}

	public function restoreBindingAfterInstall(): void
	{
		$this->transaction(function (): void {
			$pointer = Application::getConnection()->query(
				"SELECT VALUE FROM b_option WHERE MODULE_ID = 'disk' AND NAME = '" . self::BINDING_OPTION
				. "' FOR UPDATE",
			)->fetch();
			if ($pointer)
			{
				return;
			}
			$policies = SignaturePolicyTable::query()->setSelect(['BINDING_ID'])->setLimit(2)->fetchAll();
			if (count($policies) !== 1)
			{
				return;
			}
			$row = $this->readPolicy($policies[0]['BINDING_ID'], true);
			if ($row !== null)
			{
				$this->validateRow($row);
				Option::set('disk', self::BINDING_OPTION, $row['BINDING_ID']);
			}
		});
	}

	public function bind(array $status, ?callable $publishCredentials = null): array
	{
		$status = $this->validateStatus($status);

		try
		{
			return $this->transaction(function () use ($status, $publishCredentials): array {
				$current = $this->readCurrent(true);
				$id = hash('sha256', $status['platform_instance_id'] . "\0" . $status['host_id']);
				if ($current !== null && $current['BINDING_ID'] !== $id
					&& ($publishCredentials === null || $current['HOST_ID'] === $status['host_id']))
				{
					throw new RuntimeException('A different host requires atomic credential publication.');
				}
				$row = $this->readPolicy($id, true);
				if ($row === null)
				{
					$minimum = $status['send_min'] === 2 && in_array($status['phase'], ['prepared', 'complete'], true)
						? 2 : 1;
					$row = [
						'BINDING_ID' => $id,
						'PLATFORM_ID' => $status['platform_instance_id'], 'HOST_ID' => $status['host_id'],
						'RECEIVE_MIN' => $minimum, 'TRANSITION_ID' => $status['transition_id'] ?: null,
						'SENDER_EPOCH' => $status['epoch'], 'CONTRACT_ID' => SignatureV2::CONTRACT_ID,
						'PHASE' => $minimum === 2 ? 'v2_only' : 'legacy',
					];
					if (!SignaturePolicyTable::add($row)->isSuccess())
					{
						throw new RuntimeException('Could not persist the webhook binding.');
					}
				}
				$this->validateRow($row);
				$pendingBegin = $row['PHASE'] === 'preparing' && $status['send_min'] === 1
					&& $row['TRANSITION_ID'] === $status['transition_id']
					&& (int)$row['SENDER_EPOCH'] - 1 === $status['epoch'];
				if ((int)$row['RECEIVE_MIN'] > $status['send_min']
					|| ((int)$row['SENDER_EPOCH'] > $status['epoch'] && !$pendingBegin))
				{
					throw new RuntimeException('Webhook policy restoration would lower the saved minimum.');
				}
				if ($row['PHASE'] !== 'legacy' && $row['TRANSITION_ID'] !== $status['transition_id'])
				{
					throw new RuntimeException('Webhook policy restoration would replace an active operation.');
				}
				if ((int)$row['RECEIVE_MIN'] === 2 && (int)$row['SENDER_EPOCH'] !== $status['epoch'])
				{
					throw new RuntimeException('The restored webhook epoch does not match the saved policy.');
				}
				if ((int)$row['RECEIVE_MIN'] === 1 && $status['send_min'] === 2
					&& in_array($status['phase'], ['prepared', 'complete'], true))
				{
					$row = $this->update($row, [
						'RECEIVE_MIN' => 2, 'TRANSITION_ID' => $status['transition_id'],
						'SENDER_EPOCH' => $status['epoch'], 'PHASE' => 'v2_only',
					]);
				}
				Option::set('disk', self::BINDING_OPTION, $id);
				if ($publishCredentials !== null)
				{
					$row = $this->publishKeys($current, $row, $publishCredentials);
				}

				return $row;
			});
		}
		finally
		{
			if ($publishCredentials !== null)
			{
				// Option writes invalidate before commit; evict concurrent readers of the old snapshot again.
				Application::getInstance()->getManagedCache()->clean('b_option:disk', 'b_option');
			}
		}
	}


	public function refreshLegacy(string $hostId, ?callable $publishCredentials = null): array
	{
		return $this->refreshCurrent($hostId, $publishCredentials, true);
	}

	public function publishCredentials(string $hostId, callable $publishCredentials): array
	{
		return $this->refreshCurrent($hostId, $publishCredentials, false);
	}

	private function refreshCurrent(string $hostId, ?callable $publishCredentials, bool $legacyOnly): array
	{
		try
		{
			return $this->transaction(function () use ($hostId, $publishCredentials, $legacyOnly): array {
				$row = $this->requiredCurrent();
				if ($row['HOST_ID'] !== $hostId || ($legacyOnly
					&& ((int)$row['RECEIVE_MIN'] !== 1 || $row['PHASE'] !== 'legacy')))
				{
					throw new RuntimeException('A legacy response cannot restore this webhook binding.');
				}

				return $publishCredentials === null ? $row : $this->publishKeys($row, $row, $publishCredentials);
			});
		}
		finally
		{
			if ($publishCredentials !== null)
			{
				Application::getInstance()->getManagedCache()->clean('b_option:disk', 'b_option');
			}
		}
	}

	private function publishKeys(?array $current, array $row, callable $publishCredentials): array
	{
		$id = $row['BINDING_ID'];
		$keys = $publishCredentials();
		if (!is_array($keys) || !is_string($keys['current'] ?? null) || $keys['current'] === '')
		{
			throw new RuntimeException('A current webhook credential is required.');
		}
		$previous = $keys['previous'] ?? null;
		$until = $keys['previousUntil'] ?? 0;
		if (($previous !== null && (!is_string($previous) || $previous === ''))
			|| !is_int($until) || $until < 0)
		{
			throw new RuntimeException('The previous webhook credential is invalid.');
		}
		$previousMin = (int)($current['RECEIVE_MIN'] ?? 1);
		$origin = $current['KEY_FENCE'] ?? null;
		if ($origin !== null && $origin['previous'] !== null
			&& $origin['previousMin'] > (int)$row['RECEIVE_MIN']
			&& hash_equals(
				$origin['previous'], self::keyFingerprint($current['BINDING_ID'], $keys['current']),
			))
		{
			throw new RuntimeException('A protected previous key cannot become a legacy current key.');
		}
		if ($previous !== null && $origin !== null && $origin['previous'] !== null
			&& hash_equals($origin['previous'], self::keyFingerprint($current['BINDING_ID'], $previous)))
		{
			$previousMin = max($previousMin, $origin['previousMin']);
		}
		$row = $this->update($row, ['KEY_FENCE' => [
			'current' => self::keyFingerprint($id, $keys['current']),
			'previous' => $previous === null ? null : self::keyFingerprint($id, $previous),
			'previousUntil' => $previous === null ? 0 : $until,
			'previousMin' => $previousMin,
		]]);

		return $row;
	}

	public function begin(array $status): array
	{
		$status = $this->validateStatus($status);

		return $this->transaction(function () use ($status): array {
			$row = $this->requiredCurrent();
			$this->sameBinding($row, $status);
			$transition = $row['TRANSITION_ID'] ?? '';
			if ($transition !== '' && $row['PHASE'] !== 'legacy' && $transition !== $status['transition_id'])
			{
				throw new RuntimeException('A different webhook transition is already active.');
			}
			if ((int)$row['RECEIVE_MIN'] === 2)
			{
				$this->sameOperation($row, $status);

				return $row;
			}
			if ($status['transition_id'] === ''
				|| (!$status['rollout_authorized'] && $status['send_min'] !== 2))
			{
				throw new RuntimeException('The operator has not authorized a webhook transition.');
			}
			if ($row['PHASE'] === 'preparing')
			{
				return $row;
			}

			if ($status['send_min'] === 1 && $status['epoch'] === PHP_INT_MAX)
			{
				throw new RuntimeException('The sender epoch is exhausted.');
			}

			return $this->update($row, [
				'TRANSITION_ID' => $status['transition_id'],
				'SENDER_EPOCH' => $status['epoch'] + ($status['send_min'] === 1 ? 1 : 0), 'PHASE' => 'preparing',
			]);
		});
	}

	public function accept(
		int $version,
		callable $onAccepted,
		#[\SensitiveParameter] ?string $verifiedKey = null,
		?PlatformSource $source = null,
	): mixed
	{
		$this->transaction(function () use ($version, $verifiedKey, $source): void {
			if ($source?->departing)
			{
				$row = null;
				$receiveMin = $source->receiveMin;
				if ($source->policyBindingId !== null)
				{
					$row = $this->readPolicy($source->policyBindingId, true);
					if ($row === null)
					{
						throw new SignaturePolicyUnavailable(
							'policy_row_missing', 'The departing webhook policy is unavailable.',
						);
					}
					$this->validateRow($row);
				}
			}
			else
			{
				$row = $this->readCurrent(true, $receiveMin);
			}
			$this->assertAcceptedPolicy($row, $version, $verifiedKey, $receiveMin);
		});

		// Admission is published only after its own primary transaction committed.
		return $onAccepted();
	}

	private function assertAcceptedPolicy(
		?array $row,
		int $version,
		#[\SensitiveParameter] ?string $verifiedKey,
		int $receiveMin,
	): void
	{
		$fence = $row['KEY_FENCE'] ?? null;
		if ($fence !== null)
		{
			$fingerprint = $verifiedKey === null ? '' : self::keyFingerprint($row['BINDING_ID'], $verifiedKey);
			if (!hash_equals($fence['current'], $fingerprint)
				&& ($fence['previous'] === null || time() >= $fence['previousUntil']
					|| !hash_equals($fence['previous'], $fingerprint) || $version < $fence['previousMin']))
			{
				throw new SignatureVersionRejected('The webhook credential belongs to another snapshot.');
			}
		}
		if (!in_array($version, [1, 2], true) || $version < (int)($row['RECEIVE_MIN'] ?? $receiveMin))
		{
			throw new SignatureVersionRejected('The webhook signature version is no longer accepted.');
		}
	}

	public function cutoff(array $status): array
	{
		$status = $this->validateStatus($status);

		return $this->transaction(function () use ($status): array {
			$row = $this->requiredCurrent();
			$this->sameBinding($row, $status);
			$this->sameOperation($row, $status);
			$expectedEpoch = (int)$row['SENDER_EPOCH'];
			if ($status['send_min'] !== 2 || !in_array($status['phase'], ['prepared', 'complete'], true)
				|| $status['old_active_attempts'] !== 0 || $status['epoch'] !== $expectedEpoch
				|| $status['producer_fence_generation'] < 1)
			{
				throw new RuntimeException('The sender has not prepared this webhook transition.');
			}

			return $this->update($row, [
				'RECEIVE_MIN' => 2, 'SENDER_EPOCH' => $status['epoch'],
				'PHASE' => $row['PHASE'] === 'complete' ? 'complete' : 'v2_only',
			]);
		});
	}

	public function complete(array $status): array
	{
		$status = $this->validateStatus($status);

		return $this->transaction(function () use ($status): array {
			$row = $this->requiredCurrent();
			$this->sameBinding($row, $status);
			$this->sameOperation($row, $status);
			if ((int)$row['RECEIVE_MIN'] !== 2 || $status['send_min'] !== 2 || $status['phase'] !== 'complete'
				|| $status['epoch'] !== (int)$row['SENDER_EPOCH'] || $status['old_active_attempts'] !== 0)
			{
				throw new RuntimeException('The webhook transition has not completed.');
			}

			return $this->update($row, ['PHASE' => 'complete']);
		});
	}

	private function readCurrent(bool $lock, ?int &$receiveMin = null): ?array
	{
		$id = $this->bindingId($lock, $receiveMin);
		if ($id === null)
		{
			return null;
		}
		$row = $this->readPolicy($id, $lock);
		if ($row === null)
		{
			throw new SignaturePolicyUnavailable('policy_row_missing', 'The bound webhook policy is unavailable.');
		}
		$this->validateRow($row);

		return $row;
	}

	private function requiredCurrent(): array
	{
		return $this->readCurrent(true) ?? throw new RuntimeException('A verified webhook binding is required.');
	}

	private function bindingId(bool $lock, ?int &$receiveMin = null): ?string
	{
		$receiveMin = 1;
		// Bypass option caches: a cached absence must never reopen a protected host.
		$row = Application::getConnection()->query(
			"SELECT VALUE FROM b_option WHERE MODULE_ID = 'disk' AND NAME = '" . self::BINDING_OPTION . "'"
			. ($lock ? ' FOR UPDATE' : ''),
		)->fetch();
		if (!$row)
		{
			if (SignaturePolicyTable::query()->setSelect(['BINDING_ID'])->setLimit(1)->fetch())
			{
				throw new SignaturePolicyUnavailable(
					'policy_binding_missing', 'The active webhook binding is missing from recorded policies.',
				);
			}

			return null;
		}
		[$id, $receiveMin] = $this->parsePointer($row['VALUE']);

		return $id;
	}

	private function parsePointer(mixed $value): array
	{
		if (is_string($value) && preg_match('/\Anone:[12]\z/D', $value) === 1)
		{
			return [null, (int)substr($value, strlen(self::NONE_PREFIX))];
		}
		if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1)
		{
			throw new SignaturePolicyUnavailable('policy_binding_invalid', 'The webhook binding is damaged.');
		}

		return [$value, 1];
	}

	private function readPolicy(string $id, bool $lock): ?array
	{
		$query = SignaturePolicyTable::query()->setSelect(['*'])->where('BINDING_ID', $id);

		$row = Application::getConnection()->query($query->getQuery() . ($lock ? ' FOR UPDATE' : ''))->fetch() ?: null;
		if ($row !== null && isset($row['KEY_FENCE']))
		{
			try
			{
				$row['KEY_FENCE'] = Json::decode($row['KEY_FENCE']);
			}
			catch (\Bitrix\Main\ArgumentException | \TypeError $error)
			{
				throw new SignaturePolicyUnavailable(
					'policy_fence_invalid', 'The webhook credential fence is damaged.', $error,
				);
			}
			if (!is_array($row['KEY_FENCE']))
			{
				throw new SignaturePolicyUnavailable('policy_fence_invalid', 'The webhook credential fence is damaged.');
			}
		}

		return $row;
	}

	private function validateRow(array $row): void
	{
		$fence = $row['KEY_FENCE'] ?? null;
		if ($fence !== null && (!is_array($fence) || !is_string($fence['current'] ?? null)
			|| preg_match('/\A[0-9a-f]{64}\z/D', $fence['current']) !== 1
			|| !array_key_exists('previous', $fence)
			|| ($fence['previous'] !== null && (!is_string($fence['previous'])
				|| preg_match('/\A[0-9a-f]{64}\z/D', $fence['previous']) !== 1))
			|| !is_int($fence['previousUntil'] ?? null) || $fence['previousUntil'] < 0
			|| !in_array($fence['previousMin'] ?? null, [1, 2], true)))
		{
			throw new SignaturePolicyUnavailable('policy_fence_invalid', 'The webhook credential fence is damaged.');
		}
		if (!in_array((int)$row['RECEIVE_MIN'], [1, 2], true)
			|| $row['CONTRACT_ID'] !== SignatureV2::CONTRACT_ID
			|| $row['BINDING_ID'] !== hash('sha256', $row['PLATFORM_ID'] . "\0" . $row['HOST_ID'])
			|| !in_array($row['PHASE'], ['legacy', 'preparing', 'v2_only', 'complete'], true)
			|| ((int)$row['RECEIVE_MIN'] === 2 && !in_array($row['PHASE'], ['v2_only', 'complete'], true)))
		{
			throw new SignaturePolicyUnavailable('policy_row_invalid', 'The webhook policy is damaged.');
		}
	}

	private function validateStatus(array $status): array
	{
		return SignatureStatus::validate($status) ?? throw new RuntimeException('Invalid webhook signature status.');
	}

	private function sameBinding(array $row, array $status): void
	{
		if ($row['PLATFORM_ID'] !== $status['platform_instance_id'] || $row['HOST_ID'] !== $status['host_id']
			|| $row['CONTRACT_ID'] !== $status['contract_id'])
		{
			throw new RuntimeException('The webhook response belongs to another binding.');
		}
	}

	private function sameOperation(array $row, array $status): void
	{
		if (($row['TRANSITION_ID'] ?? '') === '' || $row['TRANSITION_ID'] !== $status['transition_id'])
		{
			throw new RuntimeException('The webhook response belongs to another transition.');
		}
	}

	private function update(array $row, array $patch): array
	{
		if (!SignaturePolicyTable::update($row['BINDING_ID'], $patch)->isSuccess())
		{
			throw new RuntimeException('Could not persist the webhook policy.');
		}

		return array_replace($row, $patch);
	}

	private static function keyFingerprint(string $bindingId, string $key): string
	{
		return hash('sha256', $bindingId . "\0" . $key);
	}

	private function primary(callable $operation): mixed
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $operation();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function transaction(callable $operation): mixed
	{
		return $this->primary(function () use ($operation): mixed {
			$connection = Application::getConnection();
			if (DocumentStateRepository::hasOpenTransaction($connection))
			{
				throw new SignaturePolicyUnavailable(
					'policy_transaction_active', 'The webhook policy requires its own transaction.',
				);
			}
			$connection->startTransaction();
			try
			{
				$result = $operation();
				$connection->commitTransaction();

				return $result;
			}
			catch (Throwable $error)
			{
				$connection->rollbackTransaction();
				throw $error;
			}
		});
	}
}
