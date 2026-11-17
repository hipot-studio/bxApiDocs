<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Box;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Disk\Document\Vibeoffice\Feature;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ConnectionGuard
{
	public const LOCK_NAME = 'disk_vibeoffice_box_admin';
	public const LOCK_WAIT_SEC = 0;

	public function __construct(
		private readonly ?Connection $connection = null,
		private readonly ?Environment $environment = null,
		private readonly ?Configuration $configuration = null,
	)
	{
	}

	/** Runs an administrator operation exclusively, without waiting for another administrator. */
	public function withLock(callable $operation): Result
	{
		$connection = $this->connection ?? Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, self::LOCK_WAIT_SEC))
		{
			return (new Result())->addError(new Error(
				'An administrator operation is already in progress. Try again.', 'vibeoffice_operation_in_progress',
			));
		}
		try
		{
			return $operation();
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}
	}

	/** Refuses box administration on cloud portals. */
	public function checkBoxPortal(): Result
	{
		$environment = $this->environment ?? ServiceLocator::getInstance()->get(Environment::class);
		if ($environment->isCloudPortal())
		{
			return (new Result())->addError(new Error(
				'This operation is available only on a box portal.', 'vibeoffice_box_only',
			));
		}

		return new Result();
	}

	/** Protects credentials supplied by the local configuration file. */
	public function checkNoLocalOverride(): Result
	{
		if (($this->configuration ?? new Configuration())->hasLocalCredentialOverride())
		{
			return (new Result())->addError(new Error(
				'Vibeoffice credentials are configured locally.', 'vibeoffice_local_override',
			));
		}

		return new Result();
	}

	/** Detects a tenant change only when the previous credentials remain readable. */
	public function isTenantSwitch(?array $managedState, bool $readable, string $currentDomain): bool
	{
		return $readable && ($managedState === null || ($managedState['domain'] ?? null) !== $currentDomain);
	}

	/** Requires the disabled flag and no active Vibeoffice work before replacing a tenant. */
	public function checkQuiet(): Result
	{
		$enabled = Feature::isEnabledOnPortal();
		$activeSessions = DocumentSessionTable::query()
			->where('SERVICE', DocumentService::Vibeoffice->value)
			->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
			->queryCountTotal()
		;
		$pendingDeliveries = DeliveryTable::query()
			->whereIn('STATUS', [
				DeliveryTable::STATUS_PENDING,
				DeliveryTable::STATUS_STAGED,
				DeliveryTable::STATUS_APPLIED,
			])
			->queryCountTotal()
		;
		if ($enabled || $activeSessions > 0 || $pendingDeliveries > 0)
		{
			return (new Result())->addError(new Error(
				'Disable Vibeoffice and wait for sessions and saved deliveries to finish.',
				'vibeoffice_quiet_required',
				[
					'enabled' => $enabled,
					'activeSessions' => (int)$activeSessions,
					'pendingDeliveries' => (int)$pendingDeliveries,
				],
			));
		}

		return new Result();
	}
}
