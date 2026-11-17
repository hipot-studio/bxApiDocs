<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Box;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;

final class ConnectionService
{
	private readonly Vibeoffice\Configuration $configuration;
	private readonly OnlyOffice\Configuration $documents;
	private readonly ConnectionGuard $guard;

	public function __construct(
		?Vibeoffice\Configuration $configuration = null,
		?OnlyOffice\Configuration $documents = null,
		?ConnectionGuard $guard = null,
		private readonly ?Closure $clientFactory = null,
	)
	{
		$this->configuration = $configuration ?? new Vibeoffice\Configuration();
		$this->documents = $documents ?? new OnlyOffice\Configuration();
		$this->guard = $guard ?? new ConnectionGuard(configuration: $this->configuration);
	}

	public function connect(string $serviceUrl, string $languageId): Result
	{
		return $this->guard->withLock(fn (): Result => $this->register($serviceUrl, $languageId, false, false, ''));
	}

	public function restore(
		string $serviceUrl,
		string $languageId,
		bool $discardPrevious,
		string $confirmedDomain = '',
	): Result
	{
		return $this->guard->withLock(fn (): Result => $this->register(
			$serviceUrl, $languageId, true, $discardPrevious, $confirmedDomain,
		));
	}

	public function rotate(): Result
	{
		return $this->guard->withLock(function (): Result {
			$box = $this->guard->checkBoxPortal();
			if (!$box->isSuccess())
			{
				return $box;
			}
			$managed = $this->configuration->getManagedState();
			if ($managed === null)
			{
				return $this->error('vibeoffice_not_connected');
			}
			$registration = $this->documents->getStoredCloudRegistrationData();
			if ($registration === null || !$this->configuration->hasReadableCredentials())
			{
				return $this->error('vibeoffice_restore_required');
			}
			$result = $this->client($registration['serverHost'])->rotate(
				$registration['clientId'], $this->configuration->getKeyId(),
			);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$vibeoffice = $result->getData()['vibeoffice'];
			if (!isset($vibeoffice['apiSecret']))
			{
				return $this->error('vibeoffice_restore_required');
			}
			if (($managed['hostId'] ?? null) !== $vibeoffice['hostId'])
			{
				return $this->error('vibeoffice_restore_required');
			}
			$this->configuration->replaceManagedCredentials([
				'server' => $vibeoffice['server'],
				'keyId' => $vibeoffice['keyId'],
				'apiSecret' => $vibeoffice['apiSecret'],
				'webhookSecret' => $this->configuration->getWebhookSecret(),
			], true, ['keyConfirmed' => false]);

			return $this->stateResult();
		});
	}

	public function getState(): array
	{
		$managed = $this->configuration->getManagedState();

		return [
			'box' => $this->guard->checkBoxPortal()->isSuccess(),
			'managed' => $managed !== null,
			'hostId' => $managed['hostId'] ?? null,
			'domain' => $managed['domain'] ?? null,
			'requestDomain' => UrlManager::getInstance()->getHostUrl(),
			'keyState' => $managed === null ? 'none' : (!empty($managed['keyConfirmed']) ? 'confirmed' : 'issued'),
			'activated' => (bool)($managed['activated'] ?? false),
			'enabled' => Vibeoffice\Feature::isEnabledOnPortal(),
			'customServerSelected' => Configuration::isCustomServerSaved(),
			'localOverride' => $this->configuration->hasLocalCredentialOverride(),
			'hasStoredRegistration' => $this->documents->getStoredCloudRegistrationData() !== null,
			'previousUntil' => $managed['previousUntil'] ?? null,
		];
	}

	private function register(
		string $serviceUrl,
		string $languageId,
		bool $restore,
		bool $discardPrevious,
		string $confirmedDomain,
	): Result
	{
		$box = $this->guard->checkBoxPortal();
		if (!$box->isSuccess())
		{
			return $box;
		}
		if (Configuration::isCustomServerSaved())
		{
			return $this->error('vibeoffice_custom_server_selected');
		}
		$local = $this->guard->checkNoLocalOverride();
		if (!$local->isSuccess())
		{
			return $local;
		}
		$managed = $this->configuration->getManagedState();
		$readable = $this->configuration->hasReadableCredentials();
		$domain = UrlManager::getInstance()->getHostUrl();
		$domainChanged = $managed !== null && ($managed['domain'] ?? null) !== $domain;
		if (!$restore && ($domainChanged || ($managed !== null && !$readable)))
		{
			return $this->error('vibeoffice_restore_required');
		}
		if ($restore && $domainChanged && $confirmedDomain !== $domain)
		{
			return $this->error('vibeoffice_domain_confirmation_required', [
				'requestDomain' => $domain, 'domain' => $managed['domain'] ?? null,
			]);
		}
		$quietChecked = false;
		if ($this->guard->isTenantSwitch($managed, $readable, $domain))
		{
			$quiet = $this->guard->checkQuiet();
			if (!$quiet->isSuccess())
			{
				return $quiet;
			}
			$quietChecked = true;
		}
		$registration = $this->documents->getStoredCloudRegistrationData();
		$client = $this->client($registration['serverHost'] ?? $serviceUrl);
		$result = $restore
			? $client->restore($languageId)
			: $client->connect($languageId, $readable ? $this->configuration->getKeyId() : null)
		;
		if (!$result->isSuccess())
		{
			return $result;
		}
		$data = $result->getData();
		$vibeoffice = $data['vibeoffice'];
		if ($readable && $managed !== null && ($managed['hostId'] ?? null) !== $vibeoffice['hostId'] && !$quietChecked)
		{
			$quiet = $this->guard->checkQuiet();
			if (!$quiet->isSuccess())
			{
				return $quiet;
			}
		}
		if (!isset($vibeoffice['apiSecret']) && !isset($vibeoffice['webhookSecret']))
		{
			if ($restore || !$readable || $managed === null
				|| ($managed['hostId'] ?? null) !== $vibeoffice['hostId']
				|| $this->configuration->getKeyId() !== $vibeoffice['keyId'])
			{
				return $this->error('vibeoffice_restore_required');
			}

			return $this->stateResult();
		}
		if (!isset($vibeoffice['apiSecret'], $vibeoffice['webhookSecret'], $data['client'], $data['documentServer']))
		{
			return $this->error('vibeoffice_documentproxy_unavailable');
		}
		$this->documents->storeCloudRegistration($data['client']);
		$this->configuration->replaceManagedCredentials([
			'server' => $vibeoffice['server'],
			'keyId' => $vibeoffice['keyId'],
			'apiSecret' => $vibeoffice['apiSecret'],
			'webhookSecret' => $vibeoffice['webhookSecret'],
		], $readable && !$discardPrevious, [
			'hostId' => $vibeoffice['hostId'],
			'domain' => $domain,
			'keyConfirmed' => false,
			'activated' => (bool)($managed['activated'] ?? false),
			'documentServerHost' => $data['documentServer']['host'],
		]);

		return $this->stateResult();
	}

	private function client(string $serviceUrl): RegistrationClient
	{
		return $this->clientFactory === null
			? new RegistrationClient($serviceUrl)
			: ($this->clientFactory)($serviceUrl)
		;
	}

	private function stateResult(): Result
	{
		return (new Result())->setData(['state' => $this->getState()]);
	}

	private function error(string $code, array $customData = []): Result
	{
		return (new Result())->addError(new Error('The Vibeoffice operation cannot be completed.', $code, $customData));
	}
}
