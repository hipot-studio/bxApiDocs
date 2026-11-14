<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Dto\Audit\AuditSettingsState;
use Bitrix\Disk\Internal\Enum\AuditRetentionPeriod;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class AuditSettingsService
{
	public const ERROR_AUDIT_FEATURE_DISABLED = 'DISK_AUDIT_FEATURE_DISABLED';
	public const ERROR_AUDIT_LICENSE_UNAVAILABLE = 'DISK_AUDIT_LICENSE_UNAVAILABLE';
	public const ERROR_AUDIT_EVENT_CAPTURE_FAILED = 'DISK_AUDIT_EVENT_CAPTURE_FAILED';
	public const ERROR_SETTINGS_UPDATE_LOCK_FAILED = 'DISK_AUDIT_SETTINGS_UPDATE_LOCK_FAILED';
	public const ERROR_INVALID_RETENTION_PERIOD = 'INVALID_RETENTION_PERIOD';
	public const ERROR_RETENTION_DECREASE_CONFIRMATION_REQUIRED = 'RETENTION_DECREASE_CONFIRMATION_REQUIRED';

	public function __construct(
		private ?AuditSettingsUpdateLock $updateLock = null,
	)
	{
		$this->updateLock ??= new AuditSettingsUpdateLock();
	}

	public function isEnabled(): bool
	{
		return Configuration::isAuditEnabled();
	}

	public function wasEverEnabled(): bool
	{
		return Configuration::wasAuditEverEnabled();
	}

	public function getRetentionPeriod(): AuditRetentionPeriod
	{
		return Configuration::getAuditRetentionPeriod();
	}

	public function getStoredRetentionPeriod(): AuditRetentionPeriod
	{
		return AuditRetentionPeriod::fromStored(
			$this->getStoredOption(Configuration::OPTION_AUDIT_RETENTION_PERIOD),
		);
	}

	public function getState(
		?AuditAvailabilityService $availabilityService = null,
	): AuditSettingsState
	{
		$availabilityService ??= new AuditAvailabilityService(settingsService: $this);
		$licenseAvailable = $availabilityService->isLicenseAvailable();

		return new AuditSettingsState(
			enabled: $availabilityService->isAuditEnabled(),
			retentionPeriod: $this->getRetentionPeriod()->value,
			wasEverEnabled: $this->wasEverEnabled(),
			canCollect: $availabilityService->canCollect(),
			canView: $availabilityService->canView(),
			canEditSettings: $availabilityService->canEditSettings(),
			licenseAvailable: $licenseAvailable,
			blockedReason: $licenseAvailable ? null : AuditSettingsState::BLOCKED_REASON_LICENSE_UNAVAILABLE,
		);
	}

	public function setEnabled(bool $enabled): Result
	{
		$oldEnabledValue = $this->getStoredOption(Configuration::OPTION_AUDIT_ENABLED);
		$oldWasEverEnabledValue = $this->getStoredOption(Configuration::OPTION_AUDIT_WAS_EVER_ENABLED);
		$writtenEnabledValue = $enabled ? 'Y' : 'N';
		$writtenWasEverEnabledValue
			= $oldWasEverEnabledValue !== 'Y' && ($enabled || $this->wasEverEnabled())
				? 'Y'
				: null;

		try
		{
			Option::set(
				Driver::INTERNAL_MODULE_ID,
				Configuration::OPTION_AUDIT_ENABLED,
				$writtenEnabledValue,
			);

			if ($writtenWasEverEnabledValue !== null)
			{
				Option::set(
					Driver::INTERNAL_MODULE_ID,
					Configuration::OPTION_AUDIT_WAS_EVER_ENABLED,
					$writtenWasEverEnabledValue,
				);
			}
		}
		catch (Throwable $exception)
		{
			if ($writtenWasEverEnabledValue !== null)
			{
				$this->compensateStoredOption(
					name: Configuration::OPTION_AUDIT_WAS_EVER_ENABLED,
					oldValue: $oldWasEverEnabledValue,
					writtenValue: $writtenWasEverEnabledValue,
				);
			}

			$this->compensateStoredOption(
				name: Configuration::OPTION_AUDIT_ENABLED,
				oldValue: $oldEnabledValue,
				writtenValue: $writtenEnabledValue,
			);

			throw $exception;
		}

		return new Result();
	}

	public function updateSettings(
		?bool $enabled,
		?string $retentionPeriod = AuditRetentionPeriod::DEFAULT_VALUE,
		bool $confirmRetentionDecrease = false,
		?CurrentUser $currentUser = null,
		?AuditAvailabilityService $availabilityService = null,
		?AuditCaptureService $captureService = null,
		?AuditSettingsEventFactory $eventFactory = null,
	): Result
	{
		$result = new Result();
		$availabilityService ??= new AuditAvailabilityService(settingsService: $this);
		$newRetentionPeriod = $retentionPeriod === null ? null : AuditRetentionPeriod::tryFrom($retentionPeriod);
		if ($retentionPeriod !== null && $newRetentionPeriod === null)
		{
			return $result->addError(new Error('Unknown audit retention period.', self::ERROR_INVALID_RETENTION_PERIOD));
		}
		if (!$availabilityService->isFeatureEnabled())
		{
			return $result->addError(new Error('Audit feature is disabled.', self::ERROR_AUDIT_FEATURE_DISABLED));
		}
		if (!$availabilityService->isLicenseAvailable())
		{
			return $result->addError(new Error('Audit license is unavailable.', self::ERROR_AUDIT_LICENSE_UNAVAILABLE));
		}
		if (!$this->updateLock->acquire())
		{
			return $result->addError(new Error(
				'Could not acquire audit settings update lock.',
				self::ERROR_SETTINGS_UPDATE_LOCK_FAILED,
			));
		}

		try
		{
			$oldEnabled = $this->getStoredOption(Configuration::OPTION_AUDIT_ENABLED) === 'Y';
			$oldRetentionPeriod = AuditRetentionPeriod::fromStored(
				$this->getStoredOption(Configuration::OPTION_AUDIT_RETENTION_PERIOD),
			);
			$enabled ??= $oldEnabled;
			$newRetentionPeriod ??= $oldRetentionPeriod;
			$retentionPeriod = $newRetentionPeriod->value;
			if ($newRetentionPeriod->isShorterThan($oldRetentionPeriod) && !$confirmRetentionDecrease)
			{
				return $result->addError(new Error(
					'Audit retention period decrease requires confirmation.',
					self::ERROR_RETENTION_DECREASE_CONFIRMATION_REQUIRED,
				));
			}
			if ($oldEnabled === $enabled && $oldRetentionPeriod === $newRetentionPeriod)
			{
				return $this->setUpdateResult($result, $availabilityService, $enabled, $retentionPeriod, []);
			}

			$oldValues = [
				Configuration::OPTION_AUDIT_ENABLED => $this->getStoredOption(Configuration::OPTION_AUDIT_ENABLED),
				Configuration::OPTION_AUDIT_WAS_EVER_ENABLED => $this->getStoredOption(
					Configuration::OPTION_AUDIT_WAS_EVER_ENABLED,
				),
				Configuration::OPTION_AUDIT_RETENTION_PERIOD => $this->getStoredOption(
					Configuration::OPTION_AUDIT_RETENTION_PERIOD,
				),
			];
			$writtenValues = [];
			try
			{
				if ($oldEnabled !== $enabled)
				{
					$writtenValues[Configuration::OPTION_AUDIT_ENABLED] = $enabled ? 'Y' : 'N';
				}
				if ($enabled && $oldValues[Configuration::OPTION_AUDIT_WAS_EVER_ENABLED] !== 'Y')
				{
					$writtenValues[Configuration::OPTION_AUDIT_WAS_EVER_ENABLED] = 'Y';
				}
				if ($oldRetentionPeriod !== $newRetentionPeriod)
				{
					$writtenValues[Configuration::OPTION_AUDIT_RETENTION_PERIOD] = $newRetentionPeriod->value;
				}
				foreach ($writtenValues as $name => $value)
				{
					Option::set(Driver::INTERNAL_MODULE_ID, $name, $value);
				}
			}
			catch (Throwable $exception)
			{
				foreach ($writtenValues as $name => $value)
				{
					$this->compensateStoredOption($name, $oldValues[$name], $value);
				}
				throw $exception;
			}

			$eventCodes = [];
			AuditCaptureService::runFailOpen(function () use (
				$oldEnabled,
				$enabled,
				$oldRetentionPeriod,
				$newRetentionPeriod,
				$currentUser,
				$captureService,
				$eventFactory,
				&$eventCodes,
			): Result {
				if ($currentUser === null || $captureService === null)
				{
					throw new \RuntimeException('Audit settings event context is required.');
				}
				$factory = $eventFactory ?? new AuditSettingsEventFactory();
				if ($oldEnabled !== $enabled)
				{
					$message = $factory->createForEnabledChange($oldEnabled, $enabled, $currentUser);
					$eventCodes[] = $message->eventCode;
					$captureService->capture($message, AuditSettingsEventFactory::allowedDetailKeys());
				}
				if ($oldRetentionPeriod !== $newRetentionPeriod)
				{
					$message = $factory->createForRetentionChange(
						$oldRetentionPeriod->value,
						$newRetentionPeriod->value,
						$currentUser,
					);
					$eventCodes[] = $message->eventCode;
					$captureService->capture($message, AuditSettingsEventFactory::retentionDetailKeys());
				}

				return new Result();
			});

			return $this->setUpdateResult($result, $availabilityService, $enabled, $retentionPeriod, $eventCodes);
		}
		finally
		{
			$this->updateLock->release();
		}
	}

	private function setUpdateResult(
		Result $result,
		AuditAvailabilityService $availabilityService,
		bool $enabled,
		string $retentionPeriod,
		array $eventCodes,
	): Result
	{
		return $result->setData([
			'state' => new AuditSettingsState(
				enabled: $enabled,
				retentionPeriod: $retentionPeriod,
				wasEverEnabled: $enabled
					|| $this->getStoredOption(Configuration::OPTION_AUDIT_WAS_EVER_ENABLED) === 'Y',
				canCollect: $availabilityService->isFeatureEnabled() && $enabled,
				canView: $availabilityService->canView(),
				canEditSettings: $availabilityService->canEditSettings(),
				licenseAvailable: $availabilityService->isLicenseAvailable(),
				blockedReason: null,
			),
			'enabled' => $enabled,
			'retentionPeriod' => $retentionPeriod,
			'auditEventCreated' => $eventCodes !== [],
			'auditEventCodes' => $eventCodes,
		]);
	}

	private function compensateStoredOption(string $name, ?string $oldValue, string $writtenValue): void
	{
		if ($this->getStoredOption($name) !== $writtenValue)
		{
			return;
		}
		if ($oldValue === null)
		{
			Option::delete(Driver::INTERNAL_MODULE_ID, ['name' => $name]);
		}
		else
		{
			Option::set(Driver::INTERNAL_MODULE_ID, $name, $oldValue);
		}
	}

	private function getStoredOption(string $name): ?string
	{
		// Option keeps a per-request cache that can predate acquisition of the update lock.
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = $connection->query(
				"SELECT VALUE FROM b_option WHERE MODULE_ID = '"
				. $helper->forSql(Driver::INTERNAL_MODULE_ID)
				. "' AND NAME = '" . $helper->forSql($name) . "'",
			)->fetch();

			return $row ? (string)$row['VALUE'] : null;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}
}
