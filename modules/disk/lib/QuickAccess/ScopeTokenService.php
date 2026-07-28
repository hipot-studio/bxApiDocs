<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Disk\QuickAccess\Storage\ScopeStorage;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Web\Json;
use LogicException;

/**
 * Class ScopeTokenService
 * Implements scope-based token management with improved security and reduced storage requirements
 */
class ScopeTokenService
{
	private const PROBABILITY_CLEANUP = 5; // % chance of cleanup on each request

	private bool $cleanupTriggered = false;
	private array $addedScopes = [];
	private array $savedFileMetadata = [];

	/**
	 * @param ScopeStorage $storage The scope-based storage
	 * @param ProviderFactory $fileInfoProviderFactory Provider factory for register own file providers
	 * @param string|null $signerKey Key for signing tokens
	 * @param UserQuickAccessTokenManager $userQuickAccessTokenManager
	 * @param QuickAccessReadinessChecker $quickAccessReadinessChecker
	 * @throws \Random\RandomException
	 */
	public function __construct(
		public readonly ScopeStorage $storage,
		public readonly ProviderFactory $fileInfoProviderFactory,
		private readonly ?string $signerKey,
		private readonly UserQuickAccessTokenManager $userQuickAccessTokenManager,
		private readonly QuickAccessReadinessChecker $quickAccessReadinessChecker,
	)
	{
		if (!$this->quickAccessReadinessChecker->isReady())
		{
			return;
		}

		if ($this->userQuickAccessTokenManager->isUserTokenSet())
		{
			$this->tryCleanupExpiredScopes();
		}
	}

	/**
	 * Attempt cleanup of expired scopes based on probability
	 *
	 * @return void
	 * @throws \Random\RandomException
	 */
	private function tryCleanupExpiredScopes(): void
	{
		if (random_int(1, 100) <= self::PROBABILITY_CLEANUP)
		{
			$this->cleanupExpiredScopes();
		}
	}

	/**
	 * Grants access for current user to the given file within the specified scope
	 *
	 * @param AttachedObject|BaseObject|int $object File object or file ID
	 * @param string $scope Scope identifier (e.g. 'chat_123')
	 * @return array|null Result information or null if failed
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 * @throws SecurityException
	 */
	public function grantAccessWithScope(mixed $object, string $scope): ?array
	{
		if (!$this->quickAccessReadinessChecker->isReady())
		{
			return null;
		}

		if ($this->grantAccessToScope($scope) === false)
		{
			return null;
		}

		$encryptedScopeData = $this->getEncryptedScopeForObject($object, $scope);
		if ($encryptedScopeData === null)
		{
			return null;
		}

		return [
			'scopeId' => $scope,
			'encryptedScope' => $encryptedScopeData,
		];
	}

	/**
	 * Grant access to the specified scope for the current user
	 *
	 * @param string $scope Scope identifier (e.g. 'chat_123')
	 * @return bool
	 * @throws ArgumentTypeException
	 */
	public function grantAccessToScope(string $scope): bool
	{
		if (!$this->quickAccessReadinessChecker->isReady())
		{
			return false;
		}

		$this->userQuickAccessTokenManager->ensureUserToken();

		if (isset($this->addedScopes[$scope]))
		{
			return true;
		}

		if (!$this->storage->addScope($this->userQuickAccessTokenManager->getUserToken(), $scope))
		{
			return false;
		}

		$this->addedScopes[$scope] = true;

		return true;
	}

	/**
	 * Returns the encrypted scope for the given file object or fileId. Will be used as _esd={} in URL.
	 *
	 * @note Must be called only after grantAccessToScope() for the same scope within the same service instance,
	 * otherwise a LogicException will be thrown.
	 *
	 * @param AttachedObject|BaseObject|int $file File object or file ID
	 * @param string $scope Scope identifier (e.g. 'chat_123')
	 * @return string|null Encrypted scope or null if failed
	 * @throws ArgumentException
	 * @throws SecurityException
	 */
	public function getEncryptedScopeForObject(mixed $file, string $scope): ?string
	{
		if (!$this->quickAccessReadinessChecker->isReady())
		{
			return null;
		}

		if (!isset($this->addedScopes[$scope]))
		{
			throw new LogicException('Scope access must be granted via grantAccessToScope() before generating encrypted scope data for scope: ' . $scope);
		}

		$provider = $this->fileInfoProviderFactory->createProvider($file);
		if ($provider === null)
		{
			return null;
		}

		$fileId = $provider->getBFileId();
		if (!isset($this->savedFileMetadata[$fileId]))
		{
			$fileInfo = $provider->getFileInfo();
			if ($fileInfo === null)
			{
				return null;
			}

			if (!$this->storage->saveFileMetadata($fileId, $fileInfo->toArray()))
			{
				return null;
			}
			
			$this->savedFileMetadata[$fileId] = true;
		}

		return $this->encryptScopeData($scope, $fileId, $provider->getFileName());
	}

	/**
	 * Generate scope cipher for URL parameters
	 *
	 * @param string $scope Scope identifier
	 * @param int $bFileId File ID (b_file.ID)
	 * @param string $filename
	 * @return string
	 * @throws ArgumentException
	 * @throws SecurityException
	 */
	private function encryptScopeData(string $scope, int $bFileId, string $filename): string
	{
		$scopeData = [
			'v' => 1,
			'scope' => $scope,
			'fileId' => $bFileId,
			'l' => $filename,
		];

		$packedData = Json::encode($scopeData);

		$cipher = new DeterministicCipher();
		$cipher->setIvSalt(\CMain::GetServerUniqID());
		$encryptedData = $cipher->encrypt($packedData, $this->signerKey);

		return strtr(
			string: base64_encode($encryptedData),
			from: '+/',
			to: '-_',
		);
	}

	/**
	 * Clean up expired scopes for the current user
	 * This is called probabilistically to avoid performance impact on every request
	 */
	private function cleanupExpiredScopes(): void
	{
		if ($this->cleanupTriggered || !$this->userQuickAccessTokenManager->isUserTokenSet())
		{
			return;
		}

		$this->cleanupTriggered = true;
		$this->storage->cleanupExpiredScopes($this->userQuickAccessTokenManager->getUserToken());
	}

	public function getTokenScopeByAttachedObject(AttachedObject $attachedModel): string
	{
		$entityType = str_replace('\\', '', $attachedModel->getEntityType());

		return $entityType . '_' . $attachedModel->getEntityId();
	}
}
