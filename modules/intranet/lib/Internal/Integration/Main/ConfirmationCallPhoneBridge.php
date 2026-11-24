<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UserPhoneAuthTable;

final class ConfirmationCallPhoneBridge
{
	public const ERROR_SESSION_USER_MISMATCH = 'CONFIRMATION_CALL_SESSION_USER_MISMATCH';
	public const ERROR_PHONE_RECORD_NOT_FOUND = 'CONFIRMATION_CALL_PHONE_RECORD_NOT_FOUND';
	public const ERROR_PHONE_MISMATCH = 'CONFIRMATION_CALL_PHONE_MISMATCH';
	public const ERROR_CONFIRMATION_REJECTED = 'CONFIRMATION_CALL_CONFIRMATION_REJECTED';

	private PhoneAuthAdapter $phoneAuth;

	public function __construct(?PhoneAuthAdapter $phoneAuth = null)
	{
		$this->phoneAuth = $phoneAuth ?? new PhoneAuthAdapter();
	}

	/**
	 * Turns a proof already collected by the caller into a native confirmation of the auth phone.
	 *
	 * The caller must be the authorized owner of the phone: an anonymous session would make
	 * the native validator authorize the user by phone code and skip the whole login contour.
	 *
	 * @throws ArgumentException
	 */
	public function confirmPhone(User $user, string $phoneE164): Result
	{
		$userId = (int)$user->getId();
		if ($userId <= 0)
		{
			throw new ArgumentException('User id must be positive.', 'user');
		}

		if (preg_match(ConfirmationCallChannelInterface::PHONE_PATTERN, $phoneE164) !== 1)
		{
			throw new ArgumentException('Phone must be a non-empty E.164 value.', 'phoneE164');
		}

		$result = new Result();

		if ((int)CurrentUser::get()->getId() !== $userId)
		{
			return $result->addError(new Error(
				'Auth phone can be confirmed only by its authorized owner.',
				self::ERROR_SESSION_USER_MISMATCH,
			));
		}

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = Application::getConnection();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$result = $this->confirmBoundPhone($user, $phoneE164);
			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable $exception)
		{
			if (!$transactionStarted)
			{
				throw $exception;
			}

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

			throw $exception;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function confirmBoundPhone(User $user, string $phoneE164): Result
	{
		$userId = (int)$user->getId();
		$result = new Result();

		$boundPhone = $this->getBoundPhone($userId);
		if ($boundPhone === null)
		{
			return $result->addError(new Error(
				'The user has no auth phone record.',
				self::ERROR_PHONE_RECORD_NOT_FOUND,
			));
		}

		if (!hash_equals($boundPhone, UserPhoneAuthTable::normalizePhoneNumber($phoneE164)))
		{
			return $result->addError(new Error(
				'The auth phone of the user does not match the confirmed one.',
				self::ERROR_PHONE_MISMATCH,
			));
		}

		try
		{
			$this->phoneAuth->confirmByNativeCode($user);
			PersonalMobilePush::createByUser($user)->clearPhoneConfirmationRequirement();
		}
		catch (SendException | ArgumentTypeException)
		{
			return $result->addError(new Error(
				'Native auth phone confirmation was rejected.',
				self::ERROR_CONFIRMATION_REJECTED,
			));
		}

		return $result;
	}

	private function getBoundPhone(int $userId): ?string
	{
		$table = UserPhoneAuthTable::getTableName();
		$row = Application::getConnection()->query(
			"SELECT PHONE_NUMBER FROM {$table} WHERE USER_ID = {$userId} FOR UPDATE",
		)->fetch();

		return $row === false ? null : (string)$row['PHONE_NUMBER'];
	}
}
