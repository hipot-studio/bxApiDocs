<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\ExternalLink;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Security\Sign\Signer;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExternalLinkPasswordProtectionService
{
	private const ATTEMPTS_TTL = 3600;
	private const CAPTCHA_ATTEMPTS_LIMIT = 3;
	private const LOCK_TIMEOUT = 0;
	private const MAX_CAPTCHA_SID_LENGTH = 32;
	private const MAX_CAPTCHA_WORD_LENGTH = 50;
	private const STORAGE_KEY_PREFIX = 'disk.external_link.password_attempts.';

	private ExternalLinkPasswordService $passwordService;
	private PersistentStorageInterface $storage;
	private Closure $captchaSidGenerator;
	private Closure $captchaChecker;
	private Closure $lockExecutor;

	public function __construct(
		?ExternalLinkPasswordService $passwordService = null,
		?PersistentStorageInterface $storage = null,
		?callable $captchaSidGenerator = null,
		?callable $captchaChecker = null,
		?callable $lockExecutor = null,
		private readonly ?LoggerInterface $logger = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->passwordService = $passwordService ?? new ExternalLinkPasswordService($this->storage, new Signer());
		$this->captchaSidGenerator = Closure::fromCallable(
			$captchaSidGenerator ?? $this->createDefaultCaptchaSidGenerator(),
		);
		$this->captchaChecker = Closure::fromCallable($captchaChecker ?? $this->createDefaultCaptchaChecker());
		$this->lockExecutor = Closure::fromCallable($lockExecutor ?? $this->createDefaultLockExecutor());
	}

	public function getState(ExternalLink $externalLink, mixed $sourceIp): PasswordProtectionState
	{
		if (!$externalLink->hasPassword())
		{
			return new PasswordProtectionState(false, null);
		}

		$normalizedSourceIp = $this->normalizeSourceIp($sourceIp);
		if ($normalizedSourceIp === null)
		{
			return new PasswordProtectionState(true, null);
		}

		$key = $this->getStorageKey($externalLink, $normalizedSourceIp);
		$attempts = $this->readAttemptsCount($key);
		if ($attempts === null)
		{
			return new PasswordProtectionState(true, $this->generateCaptchaSid());
		}

		$captchaRequired = $attempts >= self::CAPTCHA_ATTEMPTS_LIMIT;

		return new PasswordProtectionState(
			$captchaRequired,
			$captchaRequired ? $this->generateCaptchaSid() : null,
		);
	}

	public function checkPassword(
		ExternalLink $externalLink,
		mixed $password,
		mixed $sourceIp,
		mixed $captchaSid = null,
		mixed $captchaWord = null,
	): PasswordProtectionResult
	{
		if (!$externalLink->hasPassword())
		{
			return new PasswordProtectionResult(
				PasswordProtectionResult::STATUS_SUCCESS,
				false,
				null,
				true,
			);
		}

		$normalizedSourceIp = $this->normalizeSourceIp($sourceIp);
		if ($normalizedSourceIp === null)
		{
			return new PasswordProtectionResult(
				PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED,
				true,
				null,
				false,
			);
		}

		$key = $this->getStorageKey($externalLink, $normalizedSourceIp);
		$reservedAttempts = $this->reserveAttempt($key);
		if ($reservedAttempts === null)
		{
			return $this->buildSecurityRefusalResult();
		}

		if ($reservedAttempts === false)
		{
			return $this->checkPasswordAfterCaptcha(
				$externalLink,
				$password,
				$key,
				$captchaSid,
				$captchaWord,
			);
		}

		return $this->checkPasswordAfterAttemptReservation($externalLink, $password, $key, $reservedAttempts);
	}

	private function checkPasswordAfterCaptcha(
		ExternalLink $externalLink,
		mixed $password,
		string $key,
		mixed $captchaSid,
		mixed $captchaWord,
	): PasswordProtectionResult
	{
		$captchaLockKey = $this->getCaptchaLockKey($captchaSid);
		if ($captchaLockKey === null)
		{
			return $this->checkCaptcha($captchaSid, $captchaWord) ?? $this->buildSecurityRefusalResult();
		}

		$result = $this->runLocked(
			$captchaLockKey,
			function () use ($externalLink, $password, $key, $captchaSid, $captchaWord): PasswordProtectionResult {
				$captchaResult = $this->checkCaptcha($captchaSid, $captchaWord);
				if ($captchaResult !== null)
				{
					return $captchaResult;
				}

				return $this->checkPasswordAfterAttemptReservation($externalLink, $password, $key, null);
			},
		);

		return $result instanceof PasswordProtectionResult ? $result : $this->buildSecurityRefusalResult();
	}

	private function checkPasswordAfterAttemptReservation(
		ExternalLink $externalLink,
		mixed $password,
		string $key,
		int|false|null $reservedAttempts,
	): PasswordProtectionResult
	{
		$passwordChecked = true;
		if (!$this->passwordService->validate($externalLink, $this->normalizePassword($password)))
		{
			$captchaRequired = $reservedAttempts === null || $reservedAttempts >= self::CAPTCHA_ATTEMPTS_LIMIT;

			return new PasswordProtectionResult(
				PasswordProtectionResult::STATUS_WRONG_PASSWORD,
				$captchaRequired,
				$captchaRequired ? $this->generateCaptchaSid() : null,
				$passwordChecked,
			);
		}

		if ($this->resetAttempts($key) !== true)
		{
			return $this->buildCaptchaRequiredResult($passwordChecked);
		}

		if (!$this->passwordService->confirm($externalLink))
		{
			return $this->buildSecurityRefusalResult();
		}

		return new PasswordProtectionResult(
			PasswordProtectionResult::STATUS_SUCCESS,
			false,
			null,
			$passwordChecked,
		);
	}

	private function checkCaptcha(mixed $captchaSid, mixed $captchaWord): ?PasswordProtectionResult
	{
		$normalizedCaptchaSid = $this->normalizeCaptchaSid($captchaSid);
		$normalizedCaptchaWord = $this->normalizeCaptchaWord($captchaWord);
		if ($normalizedCaptchaSid === null || $normalizedCaptchaWord === null)
		{
			$status = $normalizedCaptchaSid === null && $normalizedCaptchaWord === null
				? PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED
				: PasswordProtectionResult::STATUS_CAPTCHA_INVALID;

			return new PasswordProtectionResult(
				$status,
				true,
				$this->generateCaptchaSid(),
				false,
			);
		}

		try
		{
			if (($this->captchaChecker)($normalizedCaptchaWord, $normalizedCaptchaSid) === true)
			{
				return null;
			}
		}
		catch (Throwable $exception)
		{
			$this->logWarning('Captcha check failed.', $exception);
		}

		return new PasswordProtectionResult(
			PasswordProtectionResult::STATUS_CAPTCHA_INVALID,
			true,
			$this->generateCaptchaSid(),
			false,
		);
	}

	private function readAttemptsCount(string $key): ?int
	{
		try
		{
			return $this->extractAttemptsCount($this->storage->get($key, []));
		}
		catch (Throwable $exception)
		{
			$this->logWarning('Password attempt state read failed.', $exception);

			return null;
		}
	}

	private function reserveAttempt(string $key): int|false|null
	{
		$result = $this->runLocked($key, function () use ($key): int|false|null {
			$attempts = $this->extractAttemptsCount($this->storage->get($key, []));
			if ($attempts >= self::CAPTCHA_ATTEMPTS_LIMIT)
			{
				return false;
			}

			$attempts++;
			if (
				!$this->storage->set(
					$key,
					['attempts' => $attempts],
					self::ATTEMPTS_TTL,
				)
			)
			{
				return null;
			}

			return $attempts;
		});

		return is_int($result) || $result === false ? $result : null;
	}

	private function resetAttempts(string $key): ?bool
	{
		$result = $this->runLocked($key, function () use ($key): bool {
			return $this->storage->delete($key);
		});

		return is_bool($result) ? $result : null;
	}

	private function runLocked(string $key, callable $callback): mixed
	{
		try
		{
			return ($this->lockExecutor)($key, $callback);
		}
		catch (Throwable $exception)
		{
			$this->logWarning('Password attempt lock operation failed.', $exception);

			return null;
		}
	}

	private function extractAttemptsCount(mixed $payload): int
	{
		if (!is_array($payload))
		{
			return 0;
		}

		$attempts = (int)($payload['attempts'] ?? 0);
		if ($attempts < 0)
		{
			return 0;
		}

		return min($attempts, self::CAPTCHA_ATTEMPTS_LIMIT);
	}

	private function buildCaptchaRequiredResult(bool $passwordChecked): PasswordProtectionResult
	{
		return new PasswordProtectionResult(
			PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED,
			true,
			$this->generateCaptchaSid(),
			$passwordChecked,
		);
	}

	private function buildSecurityRefusalResult(): PasswordProtectionResult
	{
		return new PasswordProtectionResult(
			PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED,
			true,
			null,
			false,
		);
	}

	private function generateCaptchaSid(): ?string
	{
		try
		{
			$captchaSid = ($this->captchaSidGenerator)();
		}
		catch (Throwable $exception)
		{
			$this->logWarning('Captcha SID generation failed.', $exception);

			return null;
		}

		return $this->normalizeCaptchaSid($captchaSid);
	}

	private function normalizePassword(mixed $password): string
	{
		return $this->castToString($password);
	}

	private function normalizeSourceIp(mixed $sourceIp): ?string
	{
		$sourceIp = trim($this->castToString($sourceIp));

		return $sourceIp === '' ? null : $sourceIp;
	}

	private function normalizeCaptchaSid(mixed $captchaSid): ?string
	{
		$captchaSid = trim($this->castToString($captchaSid));
		if (
			$captchaSid === ''
			|| mb_strlen($captchaSid) > self::MAX_CAPTCHA_SID_LENGTH
			|| !preg_match('/^[A-Za-z0-9]+$/', $captchaSid)
		)
		{
			return null;
		}

		return $captchaSid;
	}

	private function normalizeCaptchaWord(mixed $captchaWord): ?string
	{
		$captchaWord = trim($this->castToString($captchaWord));
		if ($captchaWord === '')
		{
			return null;
		}

		return mb_substr($captchaWord, 0, self::MAX_CAPTCHA_WORD_LENGTH);
	}

	private function castToString(mixed $value): string
	{
		if (is_string($value))
		{
			return $value;
		}

		if (is_scalar($value) || $value instanceof \Stringable)
		{
			return (string)$value;
		}

		return '';
	}

	private function getStorageKey(ExternalLink $externalLink, string $sourceIp): string
	{
		return self::STORAGE_KEY_PREFIX . $externalLink->getId() . '.' . hash('sha256', $sourceIp);
	}

	private function getCaptchaLockKey(mixed $captchaSid): ?string
	{
		$captchaSid = $this->normalizeCaptchaSid($captchaSid);
		if ($captchaSid === null)
		{
			return null;
		}

		return self::STORAGE_KEY_PREFIX . 'captcha.' . hash('sha256', $captchaSid);
	}

	private function createDefaultCaptchaSidGenerator(): callable
	{
		return static function (): ?string {
			global $APPLICATION;

			return is_object($APPLICATION) && method_exists($APPLICATION, 'CaptchaGetCode')
				? (string)$APPLICATION->CaptchaGetCode()
				: null;
		};
	}

	private function createDefaultCaptchaChecker(): callable
	{
		return static function (string $captchaWord, string $captchaSid): bool {
			global $APPLICATION;

			return is_object($APPLICATION)
				&& method_exists($APPLICATION, 'CaptchaCheckCode')
				&& $APPLICATION->CaptchaCheckCode($captchaWord, $captchaSid) === true;
		};
	}

	private function createDefaultLockExecutor(): callable
	{
		return static function (string $key, callable $callback): mixed {
			$connection = Application::getInstance()->getConnection();
			if (!$connection->lock($key, self::LOCK_TIMEOUT))
			{
				return null;
			}

			try
			{
				return $callback();
			}
			finally
			{
				$connection->unlock($key);
			}
		};
	}

	private function logWarning(string $message, Throwable $exception): void
	{
		$this->logger?->warning($message, [
			'exception' => $exception::class,
			'message' => $exception->getMessage(),
		]);
	}
}
