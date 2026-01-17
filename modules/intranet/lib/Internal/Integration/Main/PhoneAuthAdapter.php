<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Controller\PhoneAuth;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Security\Sign\Signer;

class PhoneAuthAdapter
{
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
}
