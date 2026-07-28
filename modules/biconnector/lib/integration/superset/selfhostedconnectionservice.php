<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Superset\Public\Commands\Database\ChangeDatabaseTokenCommand;
use Bitrix\Superset\Public\Commands\SelfHosted\RegisterSelfHostedServerCommand;
use Bitrix\Superset\Public\Commands\Server\GenerateServerJwtKeysCommand;
use Bitrix\Superset\Public\Commands\Server\PushServerJwtPublicKeyCommand;
use Bitrix\Superset\Public\Commands\Server\RefreshServerAccessTokenCommand;
use Bitrix\Superset\Public\Commands\Server\UpdateServerRuntimeStateCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Dto\ServerRuntimeStateDto;
use Bitrix\Superset\Public\Providers\SelfHostedServerProvider;
use Bitrix\Superset\Public\Providers\ServerProvider;
use Bitrix\Superset\Public\Providers\VersionProvider;

final class SelfHostedConnectionService
{
	private ?ServerReferenceDto $serverReference = null;

	public function findServerReference(): ?ServerReferenceDto
	{
		if ($this->serverReference !== null)
		{
			return $this->serverReference;
		}

		if (!$this->loadSupersetModule())
		{
			return null;
		}

		$this->rememberServerReference(SelfHostedServerProvider::findReference());

		return $this->serverReference;
	}

	private function getServerRuntimeState(): ?ServerRuntimeStateDto
	{
		$serverReference = $this->findServerReference();
		if ($serverReference === null)
		{
			return null;
		}

		return ServerProvider::findById($serverReference->getServerId());
	}

	public function getSupersetHost(): string
	{
		return $this->getServerRuntimeState()?->getHost() ?? '';
	}

	public function updateConnectionSettings(string $address, string $adminPassword): Result
	{
		$registerResult = $this->registerServer();
		if (!$registerResult->isSuccess())
		{
			return $registerResult;
		}

		$serverReference = $this->extractServerReference($registerResult);
		$previousHost = $this->getSupersetHost();
		$previousPassword = $this->getServerRuntimeState()->getAccessPassword();
		$fields = [
			'host' => trim($address),
		];
		if ($adminPassword !== '')
		{
			$fields['accessPassword'] = $adminPassword;
		}

		$connection = Application::getInstance()->getConnection();
		$connection->startTransaction();
		$updateResult = (new UpdateServerRuntimeStateCommand($serverReference, $fields))->run();
		if (!$updateResult->isSuccess())
		{
			$connection->rollbackTransaction();

			return $updateResult;
		}

		if (($adminPassword && $adminPassword !== $previousPassword) || $previousHost !== $fields['host'])
		{
			$refreshAccessTokenResult = (new RefreshServerAccessTokenCommand($serverReference))->run();
			if (!$refreshAccessTokenResult->isSuccess())
			{
				$updateResult->addErrors($refreshAccessTokenResult->getErrors());
				$connection->rollbackTransaction();

				return $updateResult;
			}
		}

		$publicKey = ServerProvider::readJwtPublicKeyById($serverReference->getServerId());
		if ($publicKey === '' || $previousHost !== $fields['host'])
		{
			$updateResult->setData([...$updateResult->getData(), 'host_changed' => true]);
			$generateKeysResult = $this->generateJwtKeys();
			if (!$generateKeysResult->isSuccess())
			{
				$updateResult->addErrors($generateKeysResult->getErrors());
				$connection->rollbackTransaction();

				return $updateResult;
			}

			$actualizeConnectionResult = $this->actualizeDatabaseConnection();
			if (!$actualizeConnectionResult->isSuccess())
			{
				$updateResult->addErrors($actualizeConnectionResult->getErrors());
				$connection->rollbackTransaction();

				return $updateResult;
			}
		}

		$connection->commitTransaction();

		return $updateResult;
	}

	public function generateJwtKeys(): Result
	{
		$registerResult = $this->registerServer();
		if (!$registerResult->isSuccess())
		{
			return $registerResult;
		}

		$serverReference = $this->extractServerReference($registerResult);

		$generateResult = (new GenerateServerJwtKeysCommand($serverReference))->run();
		if (!$generateResult->isSuccess())
		{
			return $generateResult;
		}

		$publicKey = (string)($generateResult->getData()['jwt_public_key'] ?? '');
		if ($publicKey !== '')
		{
			try
			{
				$pushResult = (new PushServerJwtPublicKeyCommand($serverReference, $publicKey))->run();
				if (!$pushResult->isSuccess())
				{
					$generateResult->addErrors($pushResult->getErrors());

					return $generateResult;
				}
			}
			catch (\Exception $exception)
			{
				$generateResult->addError(new Error($exception->getMessage()));
			}
		}

		return $generateResult;
	}

	public function readJwtPublicKey(): string
	{
		$serverReference = $this->findServerReference();

		return $serverReference ? ServerProvider::readJwtPublicKeyById($serverReference->getServerId()) : '';
	}

	public function getSupersetVersion(bool $clearCache = false): ?string
	{
		$serverReference = $this->findServerReference();
		if ($serverReference === null)
		{
			return null;
		}

		if ($clearCache)
		{
			VersionProvider::clearCache();
		}

		return (new VersionProvider($serverReference))->getSupersetVersion();
	}

	public function actualizeDatabaseConnection(): Result
	{
		$registerResult = $this->registerServer();
		if (!$registerResult->isSuccess())
		{
			return $registerResult;
		}

		$serverReference = $this->extractServerReference($registerResult);
		$token = KeyManager::getOrCreateAccessKey(CurrentUser::get());

		$changeTokenResult = (new ChangeDatabaseTokenCommand($serverReference, $token))->run();

		return $changeTokenResult;
	}

	private function registerServer(): Result
	{
		if (!$this->loadSupersetModule())
		{
			return $this->createModuleUnavailableResult();
		}

		$result = (new RegisterSelfHostedServerCommand())->run();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$serverReference = $this->extractServerReference($result);
		if ($serverReference === null)
		{
			$result->addError(new Error('Self-hosted Superset server reference was not returned.'));

			return $result;
		}

		$this->rememberServerReference($serverReference);

		return $result;
	}

	private function extractServerReference(Result $result): ?ServerReferenceDto
	{
		$serverReference = $result->getData()['server_reference'] ?? null;

		return $serverReference instanceof ServerReferenceDto ? $serverReference : null;
	}

	private function rememberServerReference(?ServerReferenceDto $serverReference): void
	{
		$this->serverReference = $serverReference;
	}

	private function loadSupersetModule(): bool
	{
		return Loader::includeModule('superset');
	}

	private function createModuleUnavailableResult(): Result
	{
		$result = new Result();
		$result->addError(new Error('Superset module is not available.'));

		return $result;
	}
}
