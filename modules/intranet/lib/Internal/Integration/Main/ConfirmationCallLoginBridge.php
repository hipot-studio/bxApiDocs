<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Internal\Integration\Security\DeferredOtpCodeProvider;
use Bitrix\Main\Application;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserPhoneAuthTable;

final class ConfirmationCallLoginBridge
{
	public const ERROR_SECURITY_UNAVAILABLE = 'CONFIRMATION_CALL_SECURITY_UNAVAILABLE';
	public const ERROR_DEFERRED_CONTEXT_UNAVAILABLE = 'CONFIRMATION_CALL_DEFERRED_CONTEXT_UNAVAILABLE';
	public const ERROR_OTP_NOT_ACTIVE = 'CONFIRMATION_CALL_OTP_NOT_ACTIVE';
	public const ERROR_LOGIN_REJECTED = 'CONFIRMATION_CALL_LOGIN_REJECTED';
	public const ERROR_PHONE_MISMATCH = 'CONFIRMATION_CALL_PHONE_MISMATCH';

	/**
	 * Finishes a login that waits for the second factor, using a native OTP of the deferred user.
	 *
	 * The user is never passed in: security takes it from the deferred login context and runs its
	 * usual check, so attempt limits, captcha, remember-device and the stored auth context keep
	 * working. A rejected check is a rejected call.
	 */
	public function login(
		bool $rememberDevice = false,
		string $captchaWord = '',
		string $captchaSid = '',
		string $phoneE164 = '',
	): Result
	{
		$result = new Result();

		if (!Loader::includeModule('security'))
		{
			return $result->addError(new Error(
				'The security module is not available.',
				self::ERROR_SECURITY_UNAVAILABLE,
			));
		}

		$codeProvider = new DeferredOtpCodeProvider();
		$code = $codeProvider->generateCodeForDeferredLogin();
		if ($code === null)
		{
			return $result->addError($this->describeCodeFailure($codeProvider));
		}

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = Application::getConnection();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			if (!$this->isDeferredPhoneCurrent($phoneE164))
			{
				$connection->commitTransaction();

				return $result->addError(new Error(
					'The auth phone changed during the deferred login.',
					self::ERROR_PHONE_MISMATCH,
				));
			}

			$isLoggedIn = $this->getSessionUser()->LoginByOtp(
				$code,
				$rememberDevice ? 'Y' : 'N',
				$captchaWord,
				$captchaSid,
			);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (TransactionException $rollbackException)
				{
					if ($rollbackException->getMessage() !== 'Nested rollbacks are unsupported.')
					{
						throw $rollbackException;
					}
				}
			}

			throw $exception;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		if ($isLoggedIn !== true)
		{
			return $result->addError(new Error(
				'The native OTP check rejected the login.',
				self::ERROR_LOGIN_REJECTED,
			));
		}

		return $result;
	}

	private function describeCodeFailure(DeferredOtpCodeProvider $codeProvider): Error
	{
		if ($codeProvider->getErrorByCode(DeferredOtpCodeProvider::ERROR_OTP_PARAMS) !== null)
		{
			return new Error(
				'There is no deferred push OTP login to finish.',
				self::ERROR_DEFERRED_CONTEXT_UNAVAILABLE,
			);
		}

		return new Error(
			'The OTP of the deferred user is not active.',
			self::ERROR_OTP_NOT_ACTIVE,
		);
	}

	private function getSessionUser(): \CUser
	{
		global $USER;

		if (!($USER instanceof \CUser))
		{
			$USER = new \CUser();
		}

		return $USER;
	}

	private function isDeferredPhoneCurrent(string $phoneE164): bool
	{
		$userId = (int)(\Bitrix\Security\Mfa\Otp::getDeferredParams()['USER_ID'] ?? 0);
		if ($userId <= 0 || $phoneE164 === '')
		{
			return false;
		}

		$table = UserPhoneAuthTable::getTableName();
		$row = Application::getConnection()->query(
			"SELECT PHONE_NUMBER FROM {$table} WHERE USER_ID = {$userId} FOR UPDATE",
		)->fetch();

		return $row !== false && hash_equals(
			(string)$row['PHONE_NUMBER'],
			UserPhoneAuthTable::normalizePhoneNumber($phoneE164),
		);
	}
}
