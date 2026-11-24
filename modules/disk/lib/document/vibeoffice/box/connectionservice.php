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
use Throwable;

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
			$override = $this->guard->checkNoLocalOverride();
			if (!$override->isSuccess())
			{
				return $override;
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
			try
			{
				$policy = (new Vibeoffice\Webhook\SignaturePolicyRepository())->getCurrent();
				if ($policy !== null && (int)$policy['RECEIVE_MIN'] === 2
					&& $policy['HOST_ID'] !== ($managed['hostId'] ?? null))
				{
					return $this->error('vibeoffice_restore_required');
				}
			}
			catch (Throwable)
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
			$publish = function () use ($vibeoffice): array {
				$snapshot = $this->configuration->replaceManagedCredentials([
					'server' => $vibeoffice['server'],
					'keyId' => $vibeoffice['keyId'],
					'apiSecret' => $vibeoffice['apiSecret'],
				], true, ['keyConfirmed' => false], preserveWebhookSecret: true);

				return $this->publicationKeys($snapshot);
			};
			if ($policy === null)
			{
				$publish();
			}
			else
			{
				try
				{
					(new Vibeoffice\Webhook\SignaturePolicyRepository())->publishCredentials(
						$vibeoffice['hostId'], $publish,
					);
				}
				catch (Throwable)
				{
					return $this->error('vibeoffice_restore_required');
				}
			}

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
		try
		{
			$policy = (new Vibeoffice\Webhook\SignaturePolicyRepository())->getCurrent();
		}
		catch (Throwable)
		{
			return $this->error('vibeoffice_restore_required');
		}
		$hostChanged = ($managed !== null && ($managed['hostId'] ?? null) !== $vibeoffice['hostId'])
			|| ($policy !== null && $policy['HOST_ID'] !== $vibeoffice['hostId']);
		$requiresQuiet = $readable || isset($vibeoffice['webhookSignature']) || $policy !== null;
		if ($hostChanged && $requiresQuiet && !$quietChecked)
		{
			$quiet = $this->guard->checkQuiet();
			if (!$quiet->isSuccess())
			{
				return $quiet;
			}
			$quietChecked = true;
		}
		if (!isset($vibeoffice['apiSecret']) && !isset($vibeoffice['webhookSecret']))
		{
			if ($restore || !$readable || $managed === null
				|| ($managed['hostId'] ?? null) !== $vibeoffice['hostId']
				|| $this->configuration->getKeyId() !== $vibeoffice['keyId'])
			{
				return $this->error('vibeoffice_restore_required');
			}

			$binding = $this->bindWebhookSignature($vibeoffice);
			if (!$binding->isSuccess())
			{
				return $binding;
			}

			return $this->stateResult();
		}
		if (!isset($vibeoffice['apiSecret'], $vibeoffice['webhookSecret'], $data['client'], $data['documentServer']))
		{
			return $this->error('vibeoffice_documentproxy_unavailable');
		}
		$tenantChanged = $managed !== null && ($managed['hostId'] ?? null) !== $vibeoffice['hostId'];
		$confirmedSwitch = $restore && $tenantChanged && $quietChecked;
		if ($tenantChanged && in_array($vibeoffice['webhookSecret'], [
			$this->configuration->getWebhookSecret(), $this->configuration->getPreviousWebhookSecret(),
		], true))
		{
			return $this->error('vibeoffice_restore_required');
		}
		$publish = function () use (
			$data, $vibeoffice, $readable, $discardPrevious, $domain, $managed,
		): array {
			$this->documents->storeCloudRegistration($data['client']);
			$snapshot = $this->configuration->replaceManagedCredentials([
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

			return $this->publicationKeys($snapshot);
		};
		$binding = $this->bindWebhookSignature($vibeoffice, $confirmedSwitch, $publish, $quietChecked);
		if (!$binding->isSuccess())
		{
			return $binding;
		}

		return $this->stateResult();
	}

	private function bindWebhookSignature(
		array $vibeoffice, bool $confirmedSwitch = false,
		?callable $publishCredentials = null, bool $quietChecked = false,
	): Result
	{
		$status = $vibeoffice['webhookSignature'] ?? null;
		try
		{
			$repository = new Vibeoffice\Webhook\SignaturePolicyRepository();
			$current = $repository->getCurrent();
			if ($current !== null)
			{
				if ($status === null)
				{
					$repository->refreshLegacy($vibeoffice['hostId'], $publishCredentials);

					return new Result();
				}
				if ($status !== null && (($current['HOST_ID'] === $status['host_id']
					&& $current['PLATFORM_ID'] !== $status['platform_instance_id'])
					|| ($current['HOST_ID'] !== $status['host_id'] && !$confirmedSwitch)))
				{
					return $this->error('vibeoffice_restore_required');
				}
				if ($status !== null && $current['HOST_ID'] !== $status['host_id'] && !$quietChecked)
				{
					$quiet = $this->guard->checkQuiet();
					if (!$quiet->isSuccess())
					{
						return $quiet;
					}
				}
			}
			if ($status !== null)
			{
				$repository->bind($status, $publishCredentials);
			}
		}
		catch (Throwable)
		{
			return $this->error('vibeoffice_restore_required');
		}
		if ($status === null && $publishCredentials !== null)
		{
			$publishCredentials();
		}

		return new Result();
	}

	private function publicationKeys(array $snapshot): array
	{
		return [
			'current' => $snapshot['credentials']['webhookSecret'],
			'previous' => $snapshot['previous']['webhookSecret'] ?? null,
			'previousUntil' => $snapshot['previous']['expiresAt'] ?? 0,
		];
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
