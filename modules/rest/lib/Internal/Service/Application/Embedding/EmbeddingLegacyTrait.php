<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Embedding;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use CRestServer;
use IRestService;
use ReflectionClass;
use Bitrix\Rest\OAuth;

trait EmbeddingLegacyTrait
{
	private AppRepository $appRepository;

	protected function setServerServiceDescription(CRestServer $server, ReflectionClass $reflection): void
	{
		if (!empty($server->getServiceDescription()))
		{
			return;
		}

		$providerClass = $reflection->getProperty('class')->getValue($server);
		if (
			!class_exists($providerClass)
			|| !is_subclass_of($providerClass, IRestService::class)
		)
		{
			return;
		}

		$serviceDescriptionProperty = $reflection->getProperty('arServiceDesc');
		$serviceDescriptionProperty->setValue(
			$server,
			(new $providerClass())->getDescription(),
		);
	}

	protected function setServerAuthData(CRestServer $server, ReflectionClass $reflection, array $scopeList = []): void
	{
		$authData['scope'] = implode(',', $scopeList);

		$this->setReflectedPropertyValue($server, $reflection, 'authData', $authData);
		$this->setReflectedPropertyValue($server, $reflection, 'authType', OAuth\Auth::AUTH_TYPE);
	}

	protected function setServerClientId(CRestServer $server, ReflectionClass $reflection, string $clientId): void
	{
		$this->setReflectedPropertyValue($server, $reflection, 'clientId', $clientId);
	}

	protected function setReflectedPropertyValue(
		CRestServer $server,
		ReflectionClass $reflection,
		string $propertyName,
		mixed $value,
	): void
	{
		$reflectedProperty = $reflection->getProperty($propertyName);
		$reflectedProperty->setValue($server, $value);
	}

	protected function getAppRepository(): AppRepository
	{
		return $this->appRepository ??= ServiceLocator::getInstance()->get('rest.repository.app');
	}
}
