<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\AdminUser;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Intranet\Internal\Integration\Main\LogoutService;
use Bitrix\Intranet\Internal\Integration\Main\OtpSigner;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Integration\Messageservice\TwoFaNetworkSender;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Security\Controller\PushOtp;
use Bitrix\Security\Mfa\OtpException;
use Bitrix\Intranet\Entity\User;

class Otp extends Controller
{
	public function configureActions(): array
	{
		return [
			...parent::configureActions(),
			'activePushOtp' => [
				'+prefilters' => [
					new AdminUser(),
				],
			],
			'sendAuthSms' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'sendMobilePush' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				User::class,
				'user',
				function ($className, $signedUserId) {
					$extractedUserId = (new OtpSigner())->extractUserId($signedUserId);

					if ($extractedUserId)
					{
						$user = (new UserRepository())->getUserById($extractedUserId);

						if ($user)
						{
							return $user;
						}
					}

					$this->addError(new Error('User not found'));

					return null;
				},
			),
			new ExactParameter(
				Phone::class,
				'phoneNumber',
				function ($className, $phoneNumber) {
					return new Phone($phoneNumber);
				},
			),
		];
	}

	public function activePushOtpAction(): Result
	{
		MobilePush::createByDefault()->makeMandatory();

		return new Result();
	}
	
	public function setupPushOtpAction(User $user, string $secret, string $sync1, array $initParams = []): bool
	{
		try
		{
			if (!(new UserPermission($user))->canEdit())
			{
				$this->addError(new Error("No rights"));

				return false;
			}
			
			$otp = PersonalMobilePush::createByUser($user);
			$otp->setup($secret, $sync1, $initParams);

			return true;
		}
		catch (OtpException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return false;
		}
	}

	public function resumeOtpAction(User $user): bool
	{
		try
		{
			if (!(new UserPermission($user))->canEdit())
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			(new PersonalOtp($user))->activate();

			return true;
		}
		catch (OtpException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return false;
		}
	}

	public function pauseOtpAction(User $user, int $days): bool
	{
		try
		{
			if (!(new UserPermission($user))->canEdit() || !(new UserPermission($user))->canDeactivate())
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			(new PersonalOtp($user))->deactivate($days);

			return true;
		}
		catch (OtpException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return false;
		}
	}

	/**
	 * @throws ArgumentException
	 */
	public function changeAuthPhoneAction(User $user, Phone $phoneNumber): bool
	{
		try
		{
			if (!(new UserPermission($user))->canEdit())
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			$verifyPhoneService = new VerifyPhoneService($user);
			$verifyPhoneService->changeAuthPhone($phoneNumber);

			if (!$verifyPhoneService->isConfirmed($phoneNumber))
			{
				$personalMobilePush = PersonalMobilePush::createByUser($user);
				$personalMobilePush->requirePhoneConfirmation();
			}

			return true;
		}
		catch (UpdateFailedException $exception)
		{
			$this->addErrors($exception->getErrors()->getValues());

			return false;
		}
		catch (ArgumentException $exception)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROLLER_OTP_ERROR_PHONE_NUMBER')));

			return false;
		}
	}

	public function sendConfirmationCodeAction(User $user, string $smsTemplate = 'SMS_USER_CONFIRM_NUMBER'): ?array
	{
		try
		{
			return VerifyPhoneService::createFor2Fa($user)->sendCodeByTemplate($smsTemplate);
		}
		catch (SendException $exception)
		{
			$this->addErrors($exception->getErrors()->getValues());
			$ts = (new UserRepository())->getTsSentConfirmationCode($user->getId()) ?? 0;
			$passed = time() - $ts;

			return ['DATE_SEND' => max(0, \CUser::PHONE_CODE_RESEND_INTERVAL - $passed)];
		}
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function confirmationPhoneNumberAction(User $user, string $code): ?bool
	{
		try
		{
			$confirmed = (new VerifyPhoneService($user))->confirmPhoneNumber($code);

			if ($confirmed)
			{
				$personalMobilePush = PersonalMobilePush::createByUser($user);
				$personalMobilePush->clearPhoneConfirmationRequirement();

				return true;
			}

			return false;
		}
		catch (SendException $exception)
		{
			$this->addErrors($exception->getErrors()->getValues());

			return false;
		}
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function sendAuthSmsAction(): ?array
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		TwoFaNetworkSender::useIfCloud();

		return $this->forward(PushOtp::class, 'sendSms');
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function sendMobilePushAction(string $channelTag): ?array
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		return $this->forward(PushOtp::class, 'sendMobilePush', [
			'channelTag' => $channelTag
		]);
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 */
	public function getConfigAction(User $user): array
	{
		if (!(new UserPermission($user))->canEdit())
		{
			$this->addError(new Error("No rights"));

			return [];
		}

		return (new PersonalOtp($user))->getOtpConfig();
	}

	public function logoutAllAction(User $user): void
	{
		if (!$user->isCurrent())
		{
			$this->addError(new Error("No rights"));

			return;
		}

		(new LogoutService($user))->logoutAll();
	}
}
