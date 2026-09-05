<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\ExternalLink;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Security\Sign\Signer;
use DateInterval;
use Exception;
use Throwable;

class ExternalLinkPasswordService
{
	protected const STORAGE_KEY_PREFIX = 'disk.external_link.password_access.';
	protected const SIGNATURE_SALT = 'disk.ext.link.password';
	protected const CONFIRMATION_TTL = 86400;

	/** @var callable|null */
	protected $sessionIdResolver;

	public function __construct(
		protected readonly PersistentStorageInterface $storage,
		protected readonly Signer $signer,
		?callable $sessionIdResolver = null,
	)
	{
		$this->sessionIdResolver = $sessionIdResolver;
	}

	public function validateAndConfirm(ExternalLink $externalLink, ?string $password): bool
	{
		if (!$externalLink->hasPassword())
		{
			return true;
		}

		if (!is_string($password) || $password === '')
		{
			return false;
		}

		if (!$externalLink->checkPassword($password))
		{
			return false;
		}

		return $this->confirm($externalLink);
	}

	public function confirm(ExternalLink $externalLink): bool
	{
		if (!$externalLink->hasPassword())
		{
			return true;
		}

		$key = $this->getStorageKey($externalLink);

		if (!is_string($key))
		{
			return false;
		}

		try
		{
			try
			{
				$this->storage->delete($key);
			}
			catch (Throwable)
			{
			}

			return $this->storage->set(
				key: $key,
				value: [
					'fingerprint' => $this->getPasswordFingerprint($externalLink),
				],
				ttl: $this->getConfirmationTtl(),
			);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	/**
	 * @param ExternalLink $externalLink
	 * @return bool
	 * @throws ArgumentTypeException
	 */
	public function isConfirmed(ExternalLink $externalLink): bool
	{
		if (!$externalLink->hasPassword())
		{
			return false;
		}

		$key = $this->getStorageKey($externalLink);

		if (!is_string($key))
		{
			return false;
		}

		try
		{
			$payload = $this->storage->get($key);
		}
		catch (Throwable)
		{
			return false;
		}

		if (!is_array($payload) || !is_string($payload['fingerprint'] ?? null))
		{
			return false;
		}

		return hash_equals($this->getPasswordFingerprint($externalLink), $payload['fingerprint']);
	}

	protected function getStorageKey(ExternalLink $externalLink): ?string
	{
		$sessionId = $this->getSessionId();

		if ($sessionId === '')
		{
			return null;
		}

		return static::STORAGE_KEY_PREFIX . $externalLink->getId() . '.' . hash('sha256', $sessionId);
	}

	protected function getSessionId(): string
	{
		if (is_callable($this->sessionIdResolver))
		{
			return (string)call_user_func($this->sessionIdResolver);
		}

		return Application::getInstance()->getSession()->getId();
	}

	/**
	 * @throws ArgumentTypeException
	 */
	protected function getPasswordFingerprint(ExternalLink $externalLink): string
	{
		return $this->signer->getSignature($externalLink->getPassword(), static::SIGNATURE_SALT);
	}

	/**
	 * @throws Exception
	 */
	protected function getConfirmationTtl(): DateInterval
	{
		return new DateInterval('PT' . static::CONFIRMATION_TTL . 'S');
	}
}
