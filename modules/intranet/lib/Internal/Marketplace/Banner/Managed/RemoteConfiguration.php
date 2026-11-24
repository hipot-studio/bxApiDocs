<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Managed;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Web\Json;

final class RemoteConfiguration
{
	public const MODULE_ID = 'intranet';
	public const OPTION_NAME = 'marketplace_banner_remote_configuration';
	public const FRESH_TTL = 86400;

	private const STORAGE_VERSION = 2;
	private const SNAPSHOT_FIELDS = [
		'version',
		'checkedAt',
		'status',
		'campaign',
	];
	private const LOCK_NAME = 'intranet.marketplace_banner.remote.1';
	private const LOCK_TIMEOUT = 0;

	private readonly RemoteClient $remoteClient;
	private readonly Connection $connection;

	public function __construct(
		?RemoteClient $remoteClient = null,
		?Connection $connection = null,
	)
	{
		$this->remoteClient = $remoteClient ?? new RemoteClient();
		$this->connection = $connection ?? Application::getConnection();
	}

	/**
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null}|null
	 */
	public function read(): ?array
	{
		$value = Option::get(self::MODULE_ID, self::OPTION_NAME, '', '');
		if ($value === '')
		{
			return null;
		}

		$snapshot = $this->decodeSnapshot($value);
		if ($snapshot === null)
		{
			$this->delete();
		}

		return $snapshot;
	}

	/**
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null}|null
	 */
	public function readFresh(?int $now = null): ?array
	{
		$snapshot = $this->read();
		if ($snapshot === null || !$this->isFresh($snapshot['checkedAt'], $now ?? time()))
		{
			return null;
		}

		return $snapshot;
	}

	/**
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null, updated: bool}|null
	 */
	public function update(?int $now = null): ?array
	{
		$currentTimestamp = $now ?? time();
		$freshSnapshot = $this->readFresh($currentTimestamp);
		if ($freshSnapshot !== null)
		{
			return $this->withUpdateFlag($freshSnapshot, false);
		}

		// Do not wait for another user's daily refresh request.
		if (!$this->connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT))
		{
			return $this->withUpdateFlag($this->readFresh($currentTimestamp), false);
		}

		try
		{
			// The configuration may have been refreshed before this request acquired the lock.
			$freshSnapshot = $this->readFresh($currentTimestamp);
			if ($freshSnapshot !== null)
			{
				return $this->withUpdateFlag($freshSnapshot, false);
			}

			$result = $this->remoteClient->fetch();
			$checkedAt = $now ?? time();
			$this->write($result['status'], $result['campaign'], $checkedAt);

			return $this->withUpdateFlag($this->readFresh($checkedAt), true);
		}
		finally
		{
			$this->connection->unlock(self::LOCK_NAME);
		}
	}

	public function writeCampaign(Campaign $campaign, ?int $checkedAt = null): void
	{
		$this->write(RemoteStatus::Campaign, $campaign, $checkedAt);
	}

	public function writeEmpty(?int $checkedAt = null): void
	{
		$this->write(RemoteStatus::Empty, null, $checkedAt);
	}

	public function writeError(?int $checkedAt = null): void
	{
		$this->write(RemoteStatus::Error, null, $checkedAt);
	}

	public function delete(): void
	{
		Option::delete(self::MODULE_ID, [
			'name' => self::OPTION_NAME,
			'site_id' => '',
		]);
	}

	public function isFresh(int $checkedAt, int $now): bool
	{
		return $checkedAt > 0 && $now < $checkedAt + self::FRESH_TTL;
	}

	private function write(RemoteStatus $status, ?Campaign $campaign, ?int $checkedAt): void
	{
		if ($status !== RemoteStatus::Campaign)
		{
			$campaign = null;
		}

		Option::set(
			self::MODULE_ID,
			self::OPTION_NAME,
			Json::encode([
				'version' => self::STORAGE_VERSION,
				'checkedAt' => $checkedAt ?? time(),
				'status' => $status->value,
				'campaign' => $campaign?->toArray(),
			]),
			'',
		);
	}

	/**
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null}|null
	 */
	private function decodeSnapshot(mixed $value): ?array
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			$data = Json::decode($value);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($data) || !$this->hasExactFields($data, self::SNAPSHOT_FIELDS))
		{
			return null;
		}

		$status = is_string($data['status']) ? RemoteStatus::tryFrom($data['status']) : null;
		if (
			$data['version'] !== self::STORAGE_VERSION
			|| !is_int($data['checkedAt'])
			|| $data['checkedAt'] <= 0
			|| $status === null
		)
		{
			return null;
		}

		$campaign = null;
		if ($status === RemoteStatus::Campaign)
		{
			if (!is_array($data['campaign']))
			{
				return null;
			}

			$campaign = Campaign::fromArray($data['campaign']);
			if ($campaign === null)
			{
				return null;
			}
		}
		elseif ($data['campaign'] !== null)
		{
			return null;
		}

		return [
			'status' => $status,
			'checkedAt' => $data['checkedAt'],
			'campaign' => $campaign,
		];
	}

	private function hasExactFields(array $data, array $fields): bool
	{
		$actualFields = array_keys($data);
		sort($actualFields);
		sort($fields);

		return $actualFields === $fields;
	}

	/**
	 * @param array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null}|null $snapshot
	 *
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null, updated: bool}|null
	 */
	private function withUpdateFlag(?array $snapshot, bool $updated): ?array
	{
		if ($snapshot !== null)
		{
			$snapshot['updated'] = $updated;
		}

		return $snapshot;
	}
}
