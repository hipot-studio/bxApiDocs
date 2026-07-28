<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Web\Json;
use Throwable;

/**
 * Class FileDataParameterService
 * Service for generating encrypted file data for quick access get-parameter _efd
 */
class FileDataParameterService
{
	public const PARAMETER_NAME = '_efd';
	private DeterministicCipher $cipher;
	private ?string $encryptionKey = null;
	/** @var array<string, string> */
	private array $encryptedFileDataCache = [];

	public function __construct(
		private readonly ?string $signerKey,
		private readonly ProviderFactory $fileInfoProviderFactory,
		private readonly UserQuickAccessTokenManager $userQuickAccessTokenManager,
		private readonly QuickAccessReadinessChecker $quickAccessReadinessChecker,
	)
	{
		$this->cipher = new DeterministicCipher();
		$this->cipher->setIvSalt(\CMain::GetServerUniqID());
	}

	/**
	 * Get encrypted file data for quick access get-parameter _efd
	 * @param mixed $file
	 * @return string|null
	 */
	public function getEncryptedFileData(mixed $file): ?string
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

		$cacheKey = $provider->getSourceId();

		if (!isset($this->encryptedFileDataCache[$cacheKey]))
		{
			$fileInfo = $provider->getFileInfo();

			if ($fileInfo === null)
			{
				return null;
			}

			$extendedFileInfo = $fileInfo->toArray();
			$extendedFileInfo['l'] = $provider->getFileName();

			try
			{
				$this->encryptedFileDataCache[$cacheKey] = $this->encryptFileData($extendedFileInfo);
			}
			catch (Throwable)
			{
				return null;
			}
		}

		return $this->encryptedFileDataCache[$cacheKey];
	}

	/**
	 * @param array $fileInfo
	 * @return string
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 * @throws SecurityException
	 */
	private function encryptFileData(array $fileInfo): string
	{
		$data = Json::encode($fileInfo);

		$encryptedData = $this->cipher->encrypt($data, $this->getEncryptionKey());

		return strtr(
			string: base64_encode($encryptedData),
			from: '+/',
			to: '-_',
		);
	}

	/**
	 * Get encryption key for cipher. It is generated based on user token, so it is unique for each user
	 * and can't be used outside of quick access context
	 * @return string
	 * @throws ArgumentTypeException
	 */
	private function getEncryptionKey(): string
	{
		if ($this->encryptionKey === null)
		{
			$this->userQuickAccessTokenManager->ensureUserToken();

			$userToken = $this->userQuickAccessTokenManager->getUserToken();

			$this->encryptionKey = hash_hmac('sha256', $userToken, $this->signerKey, true);
		}

		return $this->encryptionKey;
	}
}
