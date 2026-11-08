<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\SystemUser;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Entity\FireWizardConfig;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Public\Command\SystemUser\DeactivateIntegrationsCommand;
use Bitrix\Rest\Public\Contract\SystemUser\SystemUserIntegration;
use Bitrix\Rest\Public\Provider\SystemUserIntegrationProvider;

class SystemUserIntegrationService
{
	public function getIntegrations(User $user): array
	{
		if (!$user->isSystemUser())
		{
			return [];
		}

		return $this->collectIntegrations((int)$user->getId());
	}

	/**
	 * @return array{userIds: int[], integrations: array[]}
	 */
	public function getCollectionIntegrations(UserCollection $userCollection): array
	{
		$userIds = [];
		$integrations = [];

		foreach ($userCollection as $user)
		{
			if (!$user->isSystemUser())
			{
				continue;
			}

			$userIds[] = (int)$user->getId();
			array_push($integrations, ...$this->collectIntegrations((int)$user->getId()));
		}

		return [
			'userIds' => $userIds,
			'integrations' => $integrations,
		];
	}

	public function deactivateIntegrationsIfNeeded(User $user, FireWizardConfig $fireWizardConfig): Result
	{
		if (!$fireWizardConfig->disableIntegrations() || !$user->isSystemUser())
		{
			return new Result();
		}

		$userId = (int)$user->getId();

		if (!$this->isRestModuleIncluded())
		{
			return $this->deactivationFailure(
				$userId,
				new SystemException('Module rest is not available'),
			);
		}

		try
		{
			return $this->runDeactivateCommand($userId);
		}
		catch (\Throwable $exception)
		{
			return $this->deactivationFailure($userId, $exception);
		}
	}

	/**
	 * A failed deactivation must stop the fire flow with an operator-readable error.
	 */
	private function deactivationFailure(int $userId, \Throwable $exception): Result
	{
		$this->writeToLog(
			new SystemException(
				'Intranet: unable to deactivate system user integrations of user ' . $userId,
				0,
				__FILE__,
				__LINE__,
				$exception,
			),
		);

		return (new Result())->addError(
			new Error(
				Loc::getMessage('INTRANET_SYSTEM_USER_DEACTIVATE_INTEGRATIONS_ERROR'),
				'INTRANET_SYSTEM_USER_DEACTIVATE_INTEGRATIONS_ERROR',
			),
		);
	}

	/**
	 * A technical failure must not break the fire flow.
	 */
	private function collectIntegrations(int $userId): array
	{
		try
		{
			$integrations = $this->loadIntegrations($userId);
		}
		catch (\Throwable $exception)
		{
			$this->writeToLog(
				new SystemException(
					'Intranet: unable to load system user integrations of user ' . $userId,
					0,
					__FILE__,
					__LINE__,
					$exception,
				),
			);

			return [];
		}

		return array_map(
			static fn(SystemUserIntegration $integration): array => [
				'type' => $integration->getType(),
				'id' => $integration->getId(),
				'title' => $integration->getTitle(),
			],
			$integrations,
		);
	}

	/**
	 * @return SystemUserIntegration[]
	 */
	protected function loadIntegrations(int $userId): array
	{
		return (new SystemUserIntegrationProvider())->getByUserId($userId);
	}

	protected function isRestModuleIncluded(): bool
	{
		return Loader::includeModule('rest');
	}

	protected function runDeactivateCommand(int $userId): Result
	{
		return (new DeactivateIntegrationsCommand($userId))->run();
	}

	protected function writeToLog(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}
