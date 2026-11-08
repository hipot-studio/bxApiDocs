<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderInterface;
use Bitrix\Disk\QuickAccess\Storage\ScopeStorage;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Web\Json;

/**
 * Class ScopeTokenService
 * Implements scope-based token management with improved security and reduced storage requirements
 */
class ScopeTokenService
{
	private const PROBABILITY_CLEANUP = 5; // % chance of cleanup on each request
	private const MAX_MEMOIZED_DESCRIPTORS = 1000;

	private bool $cleanupTriggered = false;
	private array $addedScopes = [];
	private array $savedFileMetadata = [];
	private array $memoizedDescriptors = [];
	private ?DeterministicCipher $cipher = null;

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
	 * The descriptor is built for any file: neither its type nor a failure to read or to store file metadata
	 * affects the result. File metadata is saved to the storage only when grantAccessToScope() was called for
	 * the same scope within the same service instance. Without file metadata in the storage the interceptor
	 * falls back to the kernel with a full access check, so the URL parameter stays inert for such a file.
	 *
	 * @note The descriptor value is deterministic and equal with and without granted access.
	 *
	 * @param AttachedObject|BaseObject|int $file File object or file ID
	 * @param string $scope Scope identifier (e.g. 'chat_123')
	 * @return string|null Encrypted scope, or null when the subsystem is not ready
	 * or the file provider could not be created
	 * @throws ArgumentException
	 * @throws SecurityException
	 * @throws \RuntimeException When the file data is not available
	 */
	public function getEncryptedScopeForObject(mixed $file, string $scope): ?string
	{
		if (!$this->quickAccessReadinessChecker->isReady())
		{
			return null;
		}

		$provider = $this->fileInfoProviderFactory->createProvider($file);
		if ($provider === null)
		{
			return null;
		}

		$fileId = $provider->getBFileId();

		if (isset($this->addedScopes[$scope]))
		{
			$this->saveFileMetadataOnce($provider, $fileId);
		}

		return $this->encryptScopeData($scope, $fileId, $provider->getFileName());
	}

	/**
	 * Save file metadata to the storage once per file within the service instance
	 *
	 * @param ProviderInterface $provider Provider of the file
	 * @param int $bFileId File ID (b_file.ID)
	 */
	private function saveFileMetadataOnce(ProviderInterface $provider, int $bFileId): void
	{
		if (isset($this->savedFileMetadata[$bFileId]))
		{
			return;
		}

		$fileInfo = $provider->getFileInfo();
		if ($fileInfo === null)
		{
			return;
		}

		if (!$this->storage->saveFileMetadata($bFileId, $fileInfo->toArray()))
		{
			return;
		}

		$this->savedFileMetadata[$bFileId] = true;
	}

	/**
	 * Generate scope cipher for URL parameters. The result is memoized within the service instance
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
		// Memoization is kept below the metadata block of the caller: the descriptor does not depend on granted
		// access, while the metadata write does, so an earlier cache hit would silently skip a pending write.
		$memoizationKey = Json::encode([$scope, $bFileId, $filename]);
		if (isset($this->memoizedDescriptors[$memoizationKey]))
		{
			return $this->memoizedDescriptors[$memoizationKey];
		}

		$scopeData = [
			'v' => 1,
			'scope' => $scope,
			'fileId' => $bFileId,
			'l' => $filename,
		];

		$packedData = Json::encode($scopeData);
		$encryptedData = $this->getCipher()->encrypt($packedData, $this->signerKey);

		$encryptedScope = strtr(
			string: base64_encode($encryptedData),
			from: '+/',
			to: '-_',
		);

		if (count($this->memoizedDescriptors) < self::MAX_MEMOIZED_DESCRIPTORS)
		{
			$this->memoizedDescriptors[$memoizationKey] = $encryptedScope;
		}

		return $encryptedScope;
	}

	/**
	 * Cipher is reused within the service instance: its construction is far more expensive than encryption itself
	 */
	private function getCipher(): DeterministicCipher
	{
		if ($this->cipher === null)
		{
			$this->cipher = new DeterministicCipher();
			$this->cipher->setIvSalt(\CMain::GetServerUniqID());
		}

		return $this->cipher;
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
