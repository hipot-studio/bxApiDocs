<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Box;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Public\Command\ChangeDefaultViewerServiceCommand;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;
use Throwable;

final class ConfirmationService
{
	public function __construct(
		private readonly ?ConnectionGuard $guard = null,
		private readonly ?Vibeoffice\Configuration $configuration = null,
		private readonly ?OnlyOffice\Configuration $onlyOfficeConfiguration = null,
		private readonly ?Closure $httpClientFactory = null,
	)
	{
	}

	/** Confirms the stored key and completes the first activation, if it is still pending. */
	public function check(): Result
	{
		$guard = $this->guard ?? new ConnectionGuard(configuration: $this->configuration);

		return $guard->withLock(function () use ($guard): Result {
			try
			{
				return $this->checkLocked($guard);
			}
			catch (Throwable)
			{
				return $this->activationFailed();
			}
		});
	}

	private function checkLocked(ConnectionGuard $guard): Result
	{
		$box = $guard->checkBoxPortal();
		if (!$box->isSuccess())
		{
			return $box;
		}
		$override = $guard->checkNoLocalOverride();
		if (!$override->isSuccess())
		{
			return $override;
		}
		$configuration = $this->configuration ?? new Vibeoffice\Configuration();
		$managed = $configuration->getManagedState();
		if ($managed === null)
		{
			return (new Result())->addError(new Error(
				'Connect Vibeoffice before checking the connection.', 'vibeoffice_not_connected',
			));
		}
		$registration = ($this->onlyOfficeConfiguration ?? new OnlyOffice\Configuration())
			->getStoredCloudRegistrationData();
		if ($registration === null || !$configuration->hasReadableCredentials()
			|| !is_string($managed['hostId'] ?? null) || $managed['hostId'] === ''
			|| !is_string($managed['documentServerHost'] ?? null) || $managed['documentServerHost'] === '')
		{
			return (new Result())->addError(new Error(
				'Restore the Vibeoffice connection before checking it.', 'vibeoffice_restore_required',
			));
		}

		$policy = (new Vibeoffice\Webhook\SignaturePolicyRepository())->getCurrent();
		if ($policy !== null && (int)$policy['RECEIVE_MIN'] === 2 && $policy['HOST_ID'] !== $managed['hostId'])
		{
			return (new Result())->addError(new Error(
				'Restore the Vibeoffice connection before checking it.', 'vibeoffice_restore_required',
			));
		}

		$keyId = $configuration->getKeyId();
		$confirmation = (new RegistrationClient($registration['serverHost'], $this->httpClientFactory))
			->confirm($registration['clientId'], $keyId);
		if (!$confirmation->isSuccess())
		{
			return (new Result())->addErrors($confirmation->getErrors());
		}
		$vibeoffice = $confirmation->getData()['vibeoffice'];
		if ($vibeoffice['hostId'] !== ($managed['hostId'] ?? null) || $vibeoffice['keyId'] !== $keyId)
		{
			return (new Result())->addError(new Error(
				'The document service returned an unexpected connection.', 'vibeoffice_documentproxy_unavailable',
			));
		}
		$probe = $vibeoffice['probe'];
		$configuration->saveManagedState(['keyConfirmed' => true]);

		if ($probe['ok'] && !($managed['activated'] ?? false) && !Configuration::isCustomServerSaved())
		{
			$selection = (new ChangeDefaultViewerServiceCommand(OnlyOffice\OnlyOfficeHandler::getCode()))->run();
			if (!$selection->isSuccess())
			{
				return $this->activationFailed();
			}
			Option::set('disk', 'documents_enabled', 'Y');
			Configuration::resetEnabledDocuments();
			Option::set('disk', 'disk_onlyoffice_server', $managed['documentServerHost']);
			try
			{
				$configuration->enableOnPortal();
			}
			catch (ConfigurationException)
			{
				return $this->activationFailed();
			}
			// Persist the completion marker last so an interrupted activation can be repeated.
			$configuration->saveManagedState(['activated' => true]);
		}

		return (new Result())->setData(['probe' => $probe]);
	}

	private function activationFailed(): Result
	{
		return (new Result())->addError(new Error(
			'Vibeoffice activation could not be completed. Check the connection again.', 'vibeoffice_activation_failed',
		));
	}
}
