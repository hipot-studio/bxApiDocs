<?php

namespace Bitrix\Baas\Config;

use Bitrix\Main;

class Client extends Config
{
	protected const SYNCHRONIZATION_DELTA = 7200;

	protected function getModuleId(): string
	{
		return 'baas';
	}

	public function getHost(): string
	{
		$server = Main\Context::getCurrent()->getServer();

		return (new Main\Web\Uri(
			($server->getRequestScheme() === 'http' ? 'http' : 'https')
			. '://'
			. ($server->getHttpHost() ?? $server->getServerName() ?? ''),
		))->getUri();
	}

	//region Only  for migration period and only for clouds. Delete in 2025
	/**
	 * This method only for migration period and should be removed after migration
	 * @return bool
	 */
	public function isConsumptionsLogMigrated(): bool
	{
		return $this->get('migration_to_controller', 'has not even started yet') === 'finished';
	}

	/**
	 * This method only for migration period and should be removed after migration
	 */
	public function setConsumptionsLogMigrated(bool $finished = true): static
	{
		if ($finished === true)
		{
			$this->set('migration_to_controller', 'finished');
		}
		else
		{
			$this->delete('migration_to_controller');
		}

		return $this;
	}
	// endregion

	public function getSynCode(): ?string
	{
		return $this->get('verification_code');
	}

	public function setSynCode(string $code): static
	{
		$this->set('verification_code', $code);

		return $this;
	}

	public function isRegistered(): bool
	{
		return $this->get('host_key') !== null && $this->get('host_secret') !== null;
	}

	public function setRegistrationData(string $hostKey, string $hostSecret): static
	{
		$this->set('host_key', $hostKey);
		$this->set('host_secret', $hostSecret);

		return $this;
	}

	public function getRegistrationData(): array
	{
		return [
			'host_key' => $this->get('host_key'),
			'host_secret' => $this->get('host_secret'),
		];
	}

	public function setNextSyncTime(int $timestamp): static
	{
		$this->set('synchronization:next_sync_time_because_of_error', (string)$timestamp);

		return $this;
	}

	public function getNextSyncTime(): int
	{
		return (int)$this->get('synchronization:next_sync_time_because_of_error');
	}

	public function setLastSyncTime(int $timestamp): static
	{
		$this->set('synchronization:last_sync_time', (string)$timestamp);

		return $this;
	}

	public function getLastSyncTime(): int
	{
		return (int)$this->get('synchronization:last_sync_time');
	}

	public function getSyncInterval(): int
	{
		return (int)$this->get('synchronization:ttl', '86400');
	}

	public function getSyncDelta(): int
	{
		$delta = (int)$this->get('synchronization:delta', 0);
		if ($delta > 0)
		{
			return $delta;
		}

		$delta = rand(1, self::SYNCHRONIZATION_DELTA);

		$this->set('synchronization:delta', $delta);

		return $delta;
	}

	public function isTurnedOn(): bool
	{
		return $this->get('is_available') !== 'N';
	}

	public function turnOn(): static
	{
		$this->set('is_available', 'Y');

		return $this;
	}

	public function turnOff(): static
	{
		$this->set('is_available', 'N');

		return $this;
	}

	//region Migration. It is temporary
	public function getMigrationDelay(): int
	{
		return (int)$this->get('migration_delay', '0');
	}

	public function setMigrationDelay(int $seconds = 0): static
	{
		$this->set('migration_delay', (string)$seconds);

		return $this;
	}

	public function setMigrationLastSyncTime(int $timestamp): static
	{
		$this->set('last_migration_attempt', (string)$timestamp);

		return $this;
	}

	public function getMigrationLastSyncTime(): int
	{
		return (int)$this->get('last_migration_attempt', 0);
	}
	//endregion

	public function isLoggingEnabled(): bool
	{
		return $this->get('enabled_logging', 'N') === 'Y';
	}

	public function enableLogging(): static
	{
		$this->set('enabled_logging', 'Y');

		return $this;
	}

	public function disableLogging(): static
	{
		$this->delete('enabled_logging');

		return $this;
	}

	public function getBaasRegions(): array
	{
		if ($regions = $this->get('regions'))
		{
			$regions = json_decode($regions);
		}

		return is_array($regions) ? $regions : ['ru', 'kz', 'by'];
	}
}
