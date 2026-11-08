<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Engine\Access;

final class ApplicationInstallationFinalizer
{
	private const LOCK_NAME = 'rest_market_application_installation';
	private const LOCK_TIMEOUT = 5;
	public const ERROR_LOCK_NOT_ACQUIRED = 'APPLICATION_INSTALLATION_LOCK_NOT_ACQUIRED';
	public const ERROR_MARKET_APPLICATION_LIMIT_EXCEEDED = 'MARKET_APPLICATION_LIMIT_EXCEEDED';
	public const ERROR_UPDATE_FAILED = 'APPLICATION_INSTALLATION_UPDATE_FAILED';
	public const ERROR_ROLLBACK_FAILED = 'APPLICATION_INSTALLATION_ROLLBACK_FAILED';

	private readonly \Closure $lockProvider;
	private readonly \Closure $unlockProvider;
	private readonly \Closure $installedProvider;
	private readonly \Closure $availabilityProvider;
	private readonly \Closure $updateProvider;
	private readonly \Closure $masterOnlyProvider;
	private readonly \Closure $deactivateProvider;
	private readonly \Closure $uninstallProvider;

	/**
	 * @param null|\Closure(): bool $lockProvider
	 * @param null|\Closure(): void $unlockProvider
	 * @param null|\Closure(int): bool $installedProvider
	 * @param null|\Closure(int, bool): bool $availabilityProvider
	 * @param null|\Closure(int, bool): Result $updateProvider
	 * @param null|\Closure(bool): void $masterOnlyProvider
	 * @param null|\Closure(int): Result $deactivateProvider
	 * @param null|\Closure(int): void $uninstallProvider
	 */
	public function __construct(
		?\Closure $lockProvider = null,
		?\Closure $unlockProvider = null,
		?\Closure $installedProvider = null,
		?\Closure $availabilityProvider = null,
		?\Closure $updateProvider = null,
		?\Closure $masterOnlyProvider = null,
		?\Closure $deactivateProvider = null,
		?\Closure $uninstallProvider = null,
	)
	{
		$this->lockProvider = $lockProvider
			?? static fn(): bool => Application::getConnection()->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
		$this->unlockProvider = $unlockProvider
			?? static function (): void {
				Application::getConnection()->unlock(self::LOCK_NAME);
			};
		$this->installedProvider = $installedProvider
			?? static fn(int $appId): bool => AppTable::isInstalled($appId);
		$this->availabilityProvider = $availabilityProvider ?? Access::canInstallAppById(...);
		$this->updateProvider = $updateProvider ?? self::updateApplication(...);
		$this->masterOnlyProvider = $masterOnlyProvider ?? static function (bool $mode): void {
			Application::getInstance()->getConnectionPool()->useMasterOnly($mode);
		};
		$this->deactivateProvider = $deactivateProvider ?? self::deactivateApplication(...);
		$this->uninstallProvider = $uninstallProvider ?? self::uninstallApplication(...);
	}

	public function finalize(int $appId, bool $installed): Result
	{
		if (!$installed)
		{
			return ($this->updateProvider)($appId, false);
		}

		$result = null;
		$shouldUninstall = false;

		($this->masterOnlyProvider)(true);
		try
		{
			if (!($this->lockProvider)())
			{
				return (new Result())->addError(new Error(
					'Application installation is temporarily unavailable.',
					self::ERROR_LOCK_NOT_ACQUIRED,
				));
			}

			try
			{
				if (($this->installedProvider)($appId))
				{
					return new Result();
				}

				if (!(($this->availabilityProvider)($appId, true)))
				{
					$result = (new Result())->addError(new Error(
						'Application cannot be installed on the current plan.',
						self::ERROR_MARKET_APPLICATION_LIMIT_EXCEEDED,
					));
					$shouldUninstall = true;
					$this->deactivateRejectedApplication($appId, $result);

					return $result;
				}

				$updateResult = ($this->updateProvider)($appId, true);
				if (!$updateResult->isSuccess())
				{
					return (new Result())->addError(new Error(
						'Application installation could not be completed.',
						self::ERROR_UPDATE_FAILED,
					));
				}

				return $updateResult;
			}
			finally
			{
				($this->unlockProvider)();

				if ($shouldUninstall)
				{
					$this->uninstallRejectedApplication($appId, $result);
				}
			}
		}
		finally
		{
			($this->masterOnlyProvider)(false);
		}
	}

	private static function updateApplication(int $appId, bool $installed): Result
	{
		AppTable::setSkipRemoteUpdate(true);
		try
		{
			return AppTable::update($appId, [
				'INSTALLED' => $installed ? AppTable::INSTALLED : AppTable::NOT_INSTALLED,
				...($installed ? ['ACTIVE' => AppTable::ACTIVE] : []),
			]);
		}
		finally
		{
			AppTable::setSkipRemoteUpdate(false);
		}
	}

	private static function deactivateApplication(int $appId): Result
	{
		AppTable::setSkipRemoteUpdate(true);
		try
		{
			return AppTable::update($appId, [
				'ACTIVE' => AppTable::INACTIVE,
				'INSTALLED' => AppTable::NOT_INSTALLED,
			]);
		}
		finally
		{
			AppTable::setSkipRemoteUpdate(false);
		}
	}

	private static function uninstallApplication(int $appId): void
	{
		AppTable::uninstall($appId, true);
	}

	private function deactivateRejectedApplication(int $appId, ?Result $result): void
	{
		try
		{
			$deactivationResult = ($this->deactivateProvider)($appId);
			if (!$deactivationResult->isSuccess())
			{
				$this->reportRollbackFailure(
					$result,
					new \RuntimeException(implode('; ', $deactivationResult->getErrorMessages())),
				);
			}
		}
		catch (\Throwable $exception)
		{
			$this->reportRollbackFailure($result, $exception);
		}
	}

	private function uninstallRejectedApplication(int $appId, ?Result $result): void
	{
		try
		{
			($this->uninstallProvider)($appId);
		}
		catch (\Throwable $exception)
		{
			$this->reportRollbackFailure($result, $exception);
		}
	}

	private function reportRollbackFailure(?Result $result, \Throwable $rollbackFailure): void
	{
		if (
			$result !== null
			&& $result->getErrorCollection()->getErrorByCode(self::ERROR_ROLLBACK_FAILED) === null
		)
		{
			$result->addError(new Error(
				'Application installation rollback could not be completed.',
				self::ERROR_ROLLBACK_FAILED,
			));
		}

		Application::getInstance()->getExceptionHandler()->writeToLog($rollbackFailure);
	}
}
