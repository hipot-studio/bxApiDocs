<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseSync;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Superset\Public\Commands\Database\ChangeDatabaseTokenCommand;
use Bitrix\Superset\Public\Commands\SelfHosted\RegisterSelfHostedServerCommand;
use Bitrix\Superset\Public\Commands\Server\DeleteServerCommand;
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

	/**
	 * Told apart from the reference itself: an absent server is an answer as well, and without the flag every
	 * caller of a portal with no server connected would look it up in the table again.
	 */
	private bool $isServerReferenceResolved = false;

	public function findServerReference(): ?ServerReferenceDto
	{
		if ($this->isServerReferenceResolved)
		{
			return $this->serverReference;
		}

		// An unavailable module is not an answer about the server, so the lookup stays unresolved.
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
		$regionResult = SupersetHostMode::checkSelfHostedRegion();
		if (!$regionResult->isSuccess())
		{
			Logger::logWarning($regionResult->getErrors(), [
				'message' => 'Self-hosted Superset connection is not allowed in this region',
			]);

			return $regionResult;
		}

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

		$isServerChanged = $previousHost !== $fields['host'];
		$isAccessRenewed = ($adminPassword && $adminPassword !== $previousPassword) || $isServerChanged;

		$connection = Application::getInstance()->getConnection();
		$connection->startTransaction();
		$updateResult = (new UpdateServerRuntimeStateCommand($serverReference, $fields))->run();
		if (!$updateResult->isSuccess())
		{
			$connection->rollbackTransaction();

			return $updateResult;
		}

		if ($isAccessRenewed)
		{
			$refreshAccessTokenResult = (new RefreshServerAccessTokenCommand($serverReference))->run();
			if (!$refreshAccessTokenResult->isSuccess())
			{
				$updateResult->addErrors($refreshAccessTokenResult->getErrors());
				$connection->rollbackTransaction();

				return $updateResult;
			}
		}

		// The settings and the token are committed on their own, so that the term below is handed over with no
		// transaction open: the delivery is several requests to the instance, and the row of the server would be
		// held for all of them. The order stays the one the instance requires - it refuses the commands that finish
		// the connection until it has a term - so what follows the delivery gets a transaction of its own.
		$connection->commitTransaction();

		if ($isAccessRenewed)
		{
			SelfHostedLicenseSync::deliverOnServerConnect($isServerChanged);
		}

		$publicKey = ServerProvider::readJwtPublicKeyById($serverReference->getServerId());
		if ($publicKey === '' || $isServerChanged)
		{
			$updateResult->setData([...$updateResult->getData(), 'host_changed' => true]);

			$connection->startTransaction();
			$generateKeysResult = $this->generateJwtKeys();
			if (!$generateKeysResult->isSuccess())
			{
				$updateResult->addErrors($generateKeysResult->getErrors());
				$connection->rollbackTransaction();
				$this->restorePreviousServer($serverReference, $previousHost, $previousPassword, $updateResult);

				return $updateResult;
			}

			$actualizeConnectionResult = $this->actualizeDatabaseConnection();
			if (!$actualizeConnectionResult->isSuccess())
			{
				$updateResult->addErrors($actualizeConnectionResult->getErrors());
				$connection->rollbackTransaction();
				$this->restorePreviousServer($serverReference, $previousHost, $previousPassword, $updateResult);

				return $updateResult;
			}

			$connection->commitTransaction();
		}

		SelfHostedLicenseSync::scheduleDeliveryOnConnect();

		return $updateResult;
	}

	/**
	 * Puts the address and the password of the server back after a failed change of them.
	 *
	 * The settings are committed before the steps that finish the connection, because those are several requests to
	 * the instance and the row of the server must not be held for all of them. So a failure of those steps has
	 * nothing left to roll back, and a portal that was working with one address would be left pointing at another
	 * one the connection to which was never finished.
	 *
	 * Nothing to restore when there was no address before: a first connection that failed leaves the settings of
	 * the attempt, the same as it did before.
	 *
	 * The marks of the delivered terms stay dropped: the agent hands the terms to the restored server once more,
	 * and a term it already holds changes nothing on it.
	 */
	private function restorePreviousServer(
		ServerReferenceDto $serverReference,
		string $previousHost,
		string $previousPassword,
		Result $result,
	): void
	{
		if ($previousHost === '')
		{
			return;
		}

		$fields = ['host' => $previousHost];
		if ($previousPassword !== '')
		{
			$fields['accessPassword'] = $previousPassword;
		}

		$connection = Application::getInstance()->getConnection();
		$connection->startTransaction();

		try
		{
			$restoreResult = (new UpdateServerRuntimeStateCommand($serverReference, $fields))->run();
			if ($restoreResult->isSuccess())
			{
				// The token is issued by the instance itself, so the one taken for the new address is of no use for
				// the restored one.
				$restoreResult = (new RefreshServerAccessTokenCommand($serverReference))->run();
			}
		}
		catch (\Throwable $exception)
		{
			$restoreResult = new Result();
			$restoreResult->addError(new Error($exception->getMessage()));
		}

		if ($restoreResult->isSuccess())
		{
			$connection->commitTransaction();

			return;
		}

		$connection->rollbackTransaction();
		Logger::logWarning($restoreResult->getErrors(), [
			'message' => 'Previous self-hosted Superset connection settings are not restored',
		]);
		$result->addErrors($restoreResult->getErrors());
	}

	/**
	 * Leaves the registration of the local server behind. A registration that is not found is nothing to delete
	 * rather than a failure: the lookup answers null both for a portal that never connected a server and for a
	 * record the criteria of the local mode do not match, and neither case leaves anything to be deleted.
	 *
	 * A switch of the mode never arrives here without a record: the reset of the instance is asked first and refuses
	 * without one. This branch covers the window between that answer and this step.
	 */
	public function disconnectServer(): Result
	{
		if (!$this->loadSupersetModule())
		{
			return $this->createModuleUnavailableResult();
		}

		$serverReference = $this->findServerReference();
		if ($serverReference === null)
		{
			return new Result();
		}

		try
		{
			$deleteResult = (new DeleteServerCommand($serverReference))->run();
		}
		catch (\Throwable $exception)
		{
			// A record already gone by the time the command resolves it - deleted by another request - is a refusal
			// and not an unhandled failure: the caller is the switch of the mode, and an exception escaping from here
			// would stop it halfway.
			$deleteResult = new Result();
			$deleteResult->addError(new Error($exception->getMessage()));

			return $deleteResult;
		}

		if ($deleteResult->isSuccess())
		{
			$this->rememberServerReference(null);
		}

		return $deleteResult;
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
		$this->isServerReferenceResolved = true;
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
