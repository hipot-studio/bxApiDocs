<?php

namespace Bitrix\Disk\Controller\Integration;

use Bitrix\Disk;
use Bitrix\Disk\Config\Feature\VibeofficeAdministrationFlag;
use Bitrix\Disk\Document;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Throwable;

final class B24Documents extends Engine\Controller
{
	public function configureActions()
	{
		return [
			'verifyDomain' => [
				'prefilters' => [],
				'+postfilters' => [
					function () {
						(new Document\OnlyOffice\Configuration())->resetTempSecretForDomainVerification();
					}
				],
			],
			'baasServiceStatus' => [
				'prefilters' => [],
			],
		];
	}

	public function unregisterCloudClientAction(string $languageId): void
	{
		if (!$this->getCurrentUser()->isAdmin())
		{
			$this->addError(new Error('Only administrator can unregister portal.'));

			return;
		}

		$unregisterCloudClientResult = (new Disk\Public\Command\UnregisterCloudClientCommand())->run();

		if ($unregisterCloudClientResult->isSuccess())
		{
			if (!Document\Vibeoffice\Feature::servesPortalEditing())
			{
				Disk\Configuration::setDefaultViewerService(Document\BitrixHandler::getCode());
			}
		}
		else
		{
			$this->addErrors($unregisterCloudClientResult->getErrors());
		}
	}

	public function registerCloudClientAction(string $serviceUrl, string $languageId): void
	{
		if (!$this->getCurrentUser()->isAdmin())
		{
			$this->addError(new Error('Only administrator can register portal and connect cloud server.'));

			return;
		}

		$configuration = new Document\OnlyOffice\Configuration();
		if ($configuration->getStoredCloudRegistrationData())
		{
			return;
		}

		$cloudRegistration = (new Document\OnlyOffice\Cloud\Registration($serviceUrl))
			->setLanguageId($languageId)
		;

		$result = $cloudRegistration->registerPortal();
		if ($result->isSuccess() && isset($result->getData()['client']))
		{
			(new Disk\Public\Command\ChangeDefaultViewerServiceCommand(
				code: Document\OnlyOffice\OnlyOfficeHandler::getCode(),
			))->run();

			$configuration->storeCloudRegistration($result->getData()['client']);

			Option::set('disk', 'documents_enabled', 'Y');
			Option::set('disk', 'disk_onlyoffice_server', $result->getData()['documentServer']['host']);
		}
		else
		{
			$this->addErrors($result->getErrors());
		}
	}

	public function getVibeofficeStateAction(Document\Vibeoffice\Box\ConnectionService $service): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}

		return $service->getState();
	}

	#[ActionFilter\Attribute\Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function connectVibeofficeAction(
		Document\Vibeoffice\Box\ConnectionService $service,
		string $serviceUrl,
		string $languageId,
	): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}

		return $this->vibeofficeResult($service->connect($serviceUrl, $languageId));
	}

	#[ActionFilter\Attribute\Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function restoreVibeofficeAction(
		Document\Vibeoffice\Box\ConnectionService $service,
		string $serviceUrl,
		string $languageId,
		string $compromised = 'N',
		string $confirmedDomain = '',
	): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}

		return $this->vibeofficeResult($service->restore(
			$serviceUrl, $languageId, $compromised === 'Y', $confirmedDomain,
		));
	}

	#[ActionFilter\Attribute\Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function checkVibeofficeAction(
		Document\Vibeoffice\Box\ConfirmationService $confirmation,
		Document\Vibeoffice\Box\ConnectionService $connection,
	): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}
		$data = $this->vibeofficeResult($confirmation->check());
		if ($data === null)
		{
			return null;
		}

		return ['state' => $connection->getState(), 'probe' => $data['probe']];
	}

	#[ActionFilter\Attribute\Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function rotateVibeofficeKeyAction(Document\Vibeoffice\Box\ConnectionService $service): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}

		return $this->vibeofficeResult($service->rotate());
	}

	#[ActionFilter\Attribute\Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function setVibeofficeEnabledAction(
		Document\Vibeoffice\Configuration $configuration,
		Document\Vibeoffice\Box\ConnectionService $service,
		Document\Vibeoffice\Box\ConnectionGuard $guard,
		string $enabled,
	): ?array
	{
		if (!$this->checkVibeofficeAdmin())
		{
			return null;
		}
		if (!in_array($enabled, ['Y', 'N'], true))
		{
			$this->addError(new Error('', 'vibeoffice_invalid_enabled', []));

			return null;
		}

		return $this->vibeofficeResult($guard->withLock(function () use (
			$configuration, $service, $guard, $enabled,
		): Result {
			$box = $guard->checkBoxPortal();
			if (!$box->isSuccess())
			{
				return $box;
			}
			if ($enabled === 'Y')
			{
				$managed = $configuration->getManagedState();
				if ($managed !== null && !($managed['activated'] ?? false))
				{
					return (new Result())->addError(new Error('', 'vibeoffice_not_activated'));
				}
				if (!$configuration->hasReadableCredentials())
				{
					return (new Result())->addError(new Error('', 'vibeoffice_not_connected'));
				}
			}
			try
			{
				if ($enabled === 'Y')
				{
					$configuration->enableOnPortal();
				}
				else
				{
					$configuration->disableOnPortal();
				}
			}
			catch (ConfigurationException)
			{
				return (new Result())->addError(new Error('', 'vibeoffice_feature_change_failed', [
					'enabled' => Document\Vibeoffice\Feature::isEnabledOnPortal(),
				]));
			}

			return (new Result())->setData(['state' => $service->getState()]);
		}));
	}

	private function checkVibeofficeAdmin(): bool
	{
		if (
			!$this->getCurrentUser()->isAdmin()
			|| !class_exists(Feature::class)
			|| !Feature::isEnabled(VibeofficeAdministrationFlag::class)
		)
		{
			$this->addError(new Error('', 'access_denied'));

			return false;
		}

		return true;
	}

	private function vibeofficeResult(Result $result): ?array
	{
		if ($result->isSuccess())
		{
			return $result->getData();
		}
		$allowed = [
			'tariff_restriction', 'demo_restriction', 'domain_verification',
			'vibeoffice_restore_required', 'vibeoffice_unknown_key', 'vibeoffice_confirm_incomplete',
			'vibeoffice_operation_in_progress', 'vibeoffice_platform_unavailable',
			'vibeoffice_http_not_allowed', 'vibeoffice_protocol_unsupported',
			'vibeoffice_service_outdated', 'vibeoffice_documentproxy_unavailable',
			'vibeoffice_box_only', 'vibeoffice_local_override', 'vibeoffice_quiet_required',
			'vibeoffice_custom_server_selected', 'vibeoffice_domain_confirmation_required',
			'vibeoffice_not_connected', 'vibeoffice_not_activated', 'vibeoffice_activation_failed',
			'vibeoffice_feature_change_failed',
		];
		foreach ($result->getErrors() as $error)
		{
			$code = in_array($error->getCode(), $allowed, true)
				? $error->getCode() : 'vibeoffice_documentproxy_unavailable';
			$data = is_array($error->getCustomData()) ? $error->getCustomData() : [];
			$this->addError(new Error('', $code, $this->vibeofficeErrorData($code, $data)));
		}

		return null;
	}

	private function vibeofficeErrorData(string $code, array $data): array
	{
		$safe = [];
		$counts = match ($code)
		{
			'vibeoffice_quiet_required' => ['activeSessions', 'pendingDeliveries'],
			'vibeoffice_confirm_incomplete' => ['revokePending'],
			default => [],
		};
		if (
			in_array($code, ['vibeoffice_quiet_required', 'vibeoffice_feature_change_failed'], true)
			&& is_bool($data['enabled'] ?? null)
		)
		{
			$safe['enabled'] = $data['enabled'];
		}
		foreach ($counts as $field)
		{
			if (is_int($data[$field] ?? null) && $data[$field] >= 0)
			{
				$safe[$field] = $data[$field];
			}
		}
		if (in_array($code, ['vibeoffice_domain_confirmation_required', 'domain_verification'], true))
		{
			foreach (['requestDomain', 'domain'] as $field)
			{
				if (!is_string($data[$field] ?? null) || strlen($data[$field]) > 2048
					|| preg_match('/[\x00-\x20\x7f]/', $data[$field]) === 1)
				{
					continue;
				}
				$uri = new Uri($data[$field]);
				if (in_array($uri->getScheme(), ['http', 'https'], true) && $uri->getHost() !== ''
					&& $uri->getUserInfo() === '' && in_array($uri->getPath(), ['', '/'], true)
					&& $uri->getQuery() === '' && $uri->getFragment() === '')
				{
					$safe[$field] = $data[$field];
				}
			}
		}
		if ($code === 'vibeoffice_platform_unavailable')
		{
			if (is_int($data['httpStatus'] ?? null) && $data['httpStatus'] >= 100 && $data['httpStatus'] <= 599)
			{
				$safe['httpStatus'] = $data['httpStatus'];
			}
			if (in_array($data['platformCode'] ?? null, [
				'unauthorized', 'rate_limited', 'internal', 'validation',
				'unknown_host', 'unknown_key', 'host_exists', 'no_webhook_url',
			], true))
			{
				$safe['platformCode'] = $data['platformCode'];
			}
		}
		if ($code === 'vibeoffice_protocol_unsupported' && ($data['supported'] ?? null) === [1])
		{
			$safe['supported'] = [1];
		}

		return $safe;
	}

	public function verifyDomainAction(): ?array
	{
		$configuration = new Document\OnlyOffice\Configuration();
		$tempSecretForDomainVerification = $configuration->getTempSecretForDomainVerification();
		if (!$tempSecretForDomainVerification)
		{
			$this->addError(new Error('Empty secret.'));

			return null;
		}

		try
		{
			$cipher = new Cipher();
			$message = base64_encode($cipher->encrypt('42', $tempSecretForDomainVerification));
		}
		catch (SecurityException $securityException)
		{
			$this->addError(new Error("Cipher doesn't happy."));

			return null;
		}

		return [
			'message' => $message,
		];
	}

	public function listAllowedServersAction(): array
	{
		$primarySettings = Configuration::getInstance()->get('b24documents');
		if (!empty($primarySettings['proxyServers']))
		{
			return [
				'servers' => $primarySettings['proxyServers'],
			];
		}

		$configuration = new Document\OnlyOffice\Configuration();
		$serverListUrl = $configuration->getB24DocumentsServerListEndpoint();
		if (!$serverListUrl)
		{
			return [];
		}

		$http = new HttpClient([
			'socketTimeout' => 5,
			'streamTimeout' => 5,
			'version' => HttpClient::HTTP_1_1,
		]);

		if ($http->get($serverListUrl) === false)
		{
			$this->addError(new Error('Server is not available.'));

			return [];
		}
		if ($http->getStatus() !== 200)
		{
			$this->addError(new Error('Server is not available. Status ' . $http->getStatus()));

			return [];
		}

		$response = Json::decode($http->getResult());
		if (!$response)
		{
			$this->addError(new Error('Could not decode response.'));

			return [];
		}

		if (empty($response['servers']))
		{
			$this->addError(new Error('Empty server list.'));

			return [];
		}

		$servers = [];
		foreach ($response['servers'] as $server)
		{
			$servers[] = [
				'proxy' => $server['proxy'],
				'region' => $server['region'] ?? null,
			];
		}

		return [
			'servers' => $servers,
		];
	}

	public function baasServiceStatusAction(string $signedData): void
	{
		if (!Document\OnlyOffice\OnlyOfficeHandler::isEnabled(true))
		{
			return;
		}

		$onlyOfficeSecretKey =
			ServiceLocator::getInstance()
				->get('disk.onlyofficeConfiguration')
				->getSecretKey(true)
		;

		$signer = (new Signer())->setKey($onlyOfficeSecretKey);

		try
		{
			$unsignedData = $signer->unsign($signedData);
			$data = Json::decode($unsignedData);
		}
		catch (Throwable)
		{
			$this->addError(new Error('Invalid data'));

			return;
		}

		$serviceCode = $data['serviceCode'] ?? null;
		$isActive = $data['isActive'] ?? null;

		if (!is_string($serviceCode) || !is_bool($isActive))
		{
			$this->addError(new Error('Invalid data'));

			return;
		}

		if ($serviceCode === Disk\Integration\Baas\BaasSessionBoostService::SERVICE_CODE)
		{
			Document\OnlyOffice\Cloud\LimitInfo::invalidateClientLimitCache();

			$newServersType =
				$isActive
					? Disk\Internal\Enum\ServersTypesEnum::Booster
					: Disk\Internal\Enum\ServersTypesEnum::Regular
			;

			$currentServersType = Disk\Configuration::getOnlyOfficeServersType();

			if ($newServersType === $currentServersType)
			{
				return;
			}

			$switchServersTypeCommand = new Disk\Internal\Command\SwitchOnlyOfficeServersTypeCommand(
				newServersType: $newServersType,
				logger: null,
			);

			$switchServersTypeResult = $switchServersTypeCommand->run();

			if (!$switchServersTypeResult->isSuccess())
			{
				$this->addErrors($switchServersTypeResult->getErrors());

				return;
			}

			$sendOnlyOfficeForceReloadEventCommand = new Disk\Internal\Command\SendOnlyOfficeForceReloadEventCommand(
				newServersType: $newServersType,
				logger: null,
			);

			$sendOnlyOfficeForceReloadEventResult = $sendOnlyOfficeForceReloadEventCommand->run();

			if (!$sendOnlyOfficeForceReloadEventResult->isSuccess())
			{
				$this->addErrors($sendOnlyOfficeForceReloadEventResult->getErrors());
			}
		}
	}
}
