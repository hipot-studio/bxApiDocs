<?php

namespace Bitrix\Disk\Controller\Integration;

use Bitrix\Disk;
use Bitrix\Disk\Document;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
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
			Disk\Configuration::setDefaultViewerService(Document\BitrixHandler::getCode());
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
		if ($configuration->getCloudRegistrationData())
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
