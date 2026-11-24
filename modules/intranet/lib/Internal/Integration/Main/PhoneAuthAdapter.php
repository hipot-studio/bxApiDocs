<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Controller\PhoneAuth;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserPhoneAuthTable;

class PhoneAuthAdapter
{
	public const ERROR_NATIVE_CODE_UNAVAILABLE = 'NATIVE_PHONE_CODE_UNAVAILABLE';

	private PhoneAuth $phoneAuth;

	public function __construct()
	{
		$this->phoneAuth = new PhoneAuth();
	}

	/**
	 * @throws SendException
	 */
	public function sendCode(Phone $phone, string $smsTemplate): array
	{
		$data = [
			'phoneNumber' => $phone->defaultFormat(),
			'smsTemplate' => $smsTemplate,
		];
		$data = $this->phoneAuth->resendCodeAction((new OtpSigner())->sign($data));
		if (count($this->phoneAuth->getErrors()) > 0)
		{
			throw new SendException(new ErrorCollection($this->phoneAuth->getErrors()));
		}

		return (array)$data;
	}

	/**
	 * @throws SendException
	 * @throws ArgumentTypeException
	 */
	public function confirm(User $user, string $code): bool
	{
		$signedData = (new Signer())->sign((string)$user->getId(), PhoneAuth::SIGNATURE_SALT);
		$this->phoneAuth->confirmAction($code, $signedData);
		if (count($this->phoneAuth->getErrors()) > 0)
		{
			throw new SendException(new ErrorCollection($this->phoneAuth->getErrors()));
		}

		return true;
	}

	/**
	 * Confirms the auth phone with a native code taken right before the check.
	 * The code stays in this scope: it is never returned, stored or logged.
	 *
	 * The generator marks the code as sent, while no sms leaves the portal here. A rejected check
	 * would leave that mark behind and hold the existing sms resend interval, so the previous mark
	 * is restored. A passed check clears the mark natively, exactly as an sms confirmation does.
	 *
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 * @throws SendException
	 */
	public function confirmByNativeCode(User $user): void
	{
		$userId = (int)$user->getId();
		if ($userId <= 0)
		{
			throw new ArgumentException('User id must be positive.', 'user');
		}

		$dateSent = $this->getDateSent($userId);

		$generated = \CUser::GeneratePhoneCode($userId);
		if (!is_array($generated))
		{
			throw new SendException(new ErrorCollection([
				new Error('Auth phone is not ready for confirmation.', self::ERROR_NATIVE_CODE_UNAVAILABLE),
			]));
		}

		try
		{
			$this->confirm($user, (string)$generated[0]);
		}
		catch (\Throwable $exception)
		{
			$this->restoreDateSent($userId, $dateSent);

			throw $exception;
		}
	}

	private function getDateSent(int $userId): ?DateTime
	{
		$row = UserPhoneAuthTable::getRow([
			'select' => ['DATE_SENT'],
			'filter' => ['=USER_ID' => $userId],
		]);

		return ($row['DATE_SENT'] ?? null) instanceof DateTime ? $row['DATE_SENT'] : null;
	}

	private function restoreDateSent(int $userId, ?DateTime $dateSent): void
	{
		try
		{
			UserPhoneAuthTable::update($userId, ['DATE_SENT' => $dateSent ?? '']);
		}
		catch (\Throwable)
		{
		}
	}
}
