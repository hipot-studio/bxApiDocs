<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\AdminUser;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\Type\Email;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Exception\SendException;
use Bitrix\Intranet\Internal\Integration\Main\LogoutService;
use Bitrix\Intranet\Internal\Integration\Main\Context\DomainProvider;
use Bitrix\Intranet\Internal\Integration\Bitrix24\NetworkLogoutUrlProvider;
use Bitrix\Intranet\Internal\Integration\Main\OtpSigner;
use Bitrix\Intranet\Internal\Integration\Main\VerifyEmailService;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Integration\Security\RecoveryCodes;
use Bitrix\Intranet\Internal\Service\Otp\DeferredSkipDeviceConnectAccess;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Internal\Service\Otp\RecoverAccessRequestService;
use Bitrix\Intranet\Internal\Service\Otp\SkipDeviceConnect;
use Bitrix\Intranet\Internal\Service\Otp\SkipDeviceConnectLockException;
use Bitrix\Intranet\Internal\Service\Otp\TrustedDeviceCheckService;
use Bitrix\Intranet\Public\Command\Otp\Notification\SendRequestRecoverAccessCommand;
use Bitrix\Intranet\Public\Command\Otp\SetLegacyOtpAllowedCommand;
use Bitrix\Intranet\Public\Provider\Otp\DayListProvider;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Application;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Storage\Exception\StorageException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpHeaders;
use Bitrix\Security\Controller\PushOtp;
use Bitrix\Security\Mfa\Otp as SecurityOtp;
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
			'sendAuthEmail' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'sendMobilePush' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'sendRequestRecoverAccess' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'isRequestRecoverAccessSent' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'canSendRequestRecoverAccess' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'getRequestRecoverAccessStatus' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'resetOtpSession' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Authentication',
				],
			],
			'generateRecoveryCodesFile' => [
				'-prefilters' => [
					'\Bitrix\Main\Engine\ActionFilter\Csrf',
				],
			],
			'logoutAll' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'startSkipDeviceConnect' => [
				'-prefilters' => [
					'\\Bitrix\\Main\\Engine\\ActionFilter\\Authentication',
				],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'completeSkipDeviceConnect' => [
				'-prefilters' => [
					'\\Bitrix\\Main\\Engine\\ActionFilter\\Authentication',
				],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'cancelSkipDeviceConnect' => [
				'-prefilters' => [
					'\\Bitrix\\Main\\Engine\\ActionFilter\\Authentication',
				],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'changeAuthPhone' => $this->getDeferredContactActionConfig(),
			'sendConfirmationCode' => $this->getDeferredContactActionConfig(),
			'confirmationPhoneNumber' => $this->getDeferredContactActionConfig(),
			'changeAuthEmail' => $this->getDeferredContactActionConfig(),
			'sendEmailConfirmation' => $this->getDeferredContactActionConfig(),
			'confirmationEmail' => $this->getDeferredContactActionConfig(),
			'isTrusted' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	private function getDeferredContactActionConfig(): array
	{
		return [
			'-prefilters' => ['\\Bitrix\\Main\\Engine\\ActionFilter\\Authentication'],
			'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
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
			new ExactParameter(
				Email::class,
				'email',
				function ($className, $email) {
					return new Email($email);
				},
			),
		];
	}

	public function activePushOtpAction(): Result
	{
		if (!Loader::includeModule('security'))
		{
			return (new Result())->addError(new Error('Module Security is not installed'));
		}

		MobilePush::createByDefault()->makeMandatory();

		return new Result();
	}

	public function startSkipDeviceConnectAction(?string $signedUserId = null): ?array
	{
		$skipDeviceConnect = $this->createSkipDeviceConnectService();

		if (!$this->isSkipDeviceConnectAllowed())
		{
			$this->addError(new Error('Skipping device connection is disabled', 'SKIP_DEVICE_CONNECT_DISABLED'));

			return null;
		}

		$user = $this->getSkipDeviceConnectUser($signedUserId);
		if (!$user)
		{
			$this->addError(new Error('No rights', 'SKIP_DEVICE_CONNECT_ACCESS_DENIED'));

			return null;
		}

		if (!$this->isSecurityModuleAvailable())
		{
			$this->addError(new Error('Module Security is not installed', 'SKIP_DEVICE_CONNECT_UNAVAILABLE'));

			return null;
		}

		$personalOtp = $this->createPersonalOtp($user);
		if (!$personalOtp->canSkipDeviceConnect())
		{
			$this->addError(new Error('OTP is already initialized', 'SKIP_DEVICE_CONNECT_OTP_INITIALIZED'));

			return null;
		}

		if (!$this->isPushOtpAvailable())
		{
			$this->addError(new Error('Push OTP is not available', 'SKIP_DEVICE_CONNECT_PUSH_UNAVAILABLE'));

			return null;
		}

		try
		{
			$flow = $skipDeviceConnect->start(
				$user,
				$this->isPhoneChannelAvailable($user),
				$this->isEmailChannelAvailable($user),
			);
		}
		catch (SkipDeviceConnectLockException)
		{
			$this->addError(new Error('Unable to start OTP setup', 'SKIP_DEVICE_CONNECT_LOCK_FAILED'));

			return null;
		}

		if ($flow === null)
		{
			$this->sendSkipDeviceConnectEvent('channel_unavailable');
			$this->addError(new Error('No confirmation channel is available', 'SKIP_DEVICE_CONNECT_NO_CHANNEL'));

			return null;
		}

		$this->sendSkipDeviceConnectEvent('started');

		return $flow;
	}

	public function completeSkipDeviceConnectAction(string $flowId, ?string $signedUserId = null): ?array
	{
		$skipDeviceConnect = $this->createSkipDeviceConnectService();
		$user = $this->getSkipDeviceConnectUser($signedUserId);

		if (!$user)
		{
			$this->addError(new Error('No rights', 'SKIP_DEVICE_CONNECT_ACCESS_DENIED'));

			return null;
		}

		try
		{
			return $skipDeviceConnect->runLocked(
				$user,
				function () use ($skipDeviceConnect, $user, $flowId): ?array {
					$availableChannels = $skipDeviceConnect->getAvailableChannels($user, $flowId);
					if ($availableChannels === [])
					{
						$this->sendSkipDeviceConnectEvent('callback_rejected');
						$this->addError(new Error('Skip device connection intent was not found', 'SKIP_DEVICE_CONNECT_NO_INTENT'));

						return null;
					}

					if ($skipDeviceConnect->isCompleted($user, $flowId))
					{
						return ['activated' => true];
					}

					$securityAvailable = $this->isSecurityModuleAvailable();
					if (
						$securityAvailable
						&& $this->wasSkipDeviceConnectCompletedByFlow($this->createPersonalOtp($user), $flowId)
					)
					{
						SecurityOtp::cleanCache($user->getId());
						$skipDeviceConnect->complete($user, $flowId);

						return ['activated' => true];
					}

					if (!$this->isSkipDeviceConnectAllowed())
					{
						$skipDeviceConnect->cancel($user, $flowId);
						$this->addError(new Error('Skipping device connection is disabled', 'SKIP_DEVICE_CONNECT_DISABLED'));

						return null;
					}

					if (!$securityAvailable || !$this->isPushOtpAvailable())
					{
						$this->addError(new Error('Push OTP is not available', 'SKIP_DEVICE_CONNECT_PUSH_UNAVAILABLE'));

						return null;
					}

					$confirmedChannel = $skipDeviceConnect->getConfirmedChannel($user, $flowId);
					if ($confirmedChannel === null)
					{
						$this->sendSkipDeviceConnectEvent('callback_rejected');
						$this->addError(new Error('The selected contact was not confirmed for this flow', 'SKIP_DEVICE_CONNECT_CHANNEL_NOT_CONFIRMED'));

						return null;
					}

					if (!$this->canLoginByChannel($user, $confirmedChannel))
					{
						$this->sendSkipDeviceConnectEvent('callback_rejected');
						$this->addError(new Error('The selected contact is not confirmed', 'SKIP_DEVICE_CONNECT_CHANNEL_UNCONFIRMED'));

						return null;
					}

					$connection = Application::getConnection();
					$connection->startTransaction();
					try
					{
						$personalOtp = $this->createPersonalOtp($user);
						if (!$personalOtp->canSkipDeviceConnect())
						{
							$connection->commitTransaction();
							$skipDeviceConnect->cancel($user, $flowId);
							$this->addError(new Error('OTP was initialized in another mode', 'SKIP_DEVICE_CONNECT_OTP_CONFLICT'));

							return null;
						}

						$personalOtp->setupSkipDeviceConnect($flowId);
						if (!$this->canLoginByChannel($user, $confirmedChannel))
						{
							$connection->rollbackTransaction();
							SecurityOtp::cleanCache($user->getId());
							$this->sendSkipDeviceConnectEvent('callback_rejected');
							$this->addError(new Error('The selected contact is not confirmed', 'SKIP_DEVICE_CONNECT_CHANNEL_UNCONFIRMED'));

							return null;
						}

						$personalOtp->activate();
						if (!$personalOtp->isActivated())
						{
							throw new OtpException('Unable to activate Push OTP');
						}

						$connection->commitTransaction();
					}
					catch (OtpException $exception)
					{
						$connection->rollbackTransaction();
						SecurityOtp::cleanCache($user->getId());
						$this->addError(new Error($exception->getMessage(), 'SKIP_DEVICE_CONNECT_ACTIVATION_FAILED'));

						return null;
					}
					catch (\Throwable $exception)
					{
						$connection->rollbackTransaction();
						SecurityOtp::cleanCache($user->getId());

						throw $exception;
					}

					SecurityOtp::cleanCache($user->getId());
					$skipDeviceConnect->complete($user, $flowId);
					$this->sendSkipDeviceConnectEvent('activated');

					return ['activated' => true];
				},
			);
		}
		catch (SkipDeviceConnectLockException)
		{
			$this->addError(new Error('Unable to complete OTP setup', 'SKIP_DEVICE_CONNECT_LOCK_FAILED'));

			return null;
		}
	}

	private function wasSkipDeviceConnectCompletedByFlow(PersonalOtp $personalOtp, string $flowId): bool
	{
		$initParams = $personalOtp->getInitParams();

		return $personalOtp->isActivated()
			&& $personalOtp->isPushType()
			&& $personalOtp->isDeviceConnectSkipped()
			&& is_string($initParams['skipDeviceConnectFlowHash'] ?? null)
			&& hash_equals($initParams['skipDeviceConnectFlowHash'], hash('sha256', $flowId))
		;
	}

	public function cancelSkipDeviceConnectAction(string $flowId, ?string $signedUserId = null): void
	{
		$user = $this->getSkipDeviceConnectUser($signedUserId);
		if ($user !== null)
		{
			try
			{
				$this->createSkipDeviceConnectService()->cancel($user, $flowId);
			}
			catch (SkipDeviceConnectLockException)
			{
				$this->addError(new Error('Unable to cancel OTP setup', 'SKIP_DEVICE_CONNECT_LOCK_FAILED'));
			}
		}
	}

	protected function createSkipDeviceConnectService(): SkipDeviceConnect
	{
		return new SkipDeviceConnect(
			ServiceLocator::getInstance()->get(PersistentStorageInterface::class),
			(new DomainProvider())->getDomain(),
		);
	}

	protected function getCurrentIntranetUser(): ?User
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		return $currentUserId > 0 ? (new UserRepository())->getUserById($currentUserId) : null;
	}

	protected function getSkipDeviceConnectUser(?string $signedUserId): ?User
	{
		$currentUser = $this->getCurrentIntranetUser();
		if ($currentUser !== null)
		{
			return $this->canCurrentUserEditOtp($currentUser) ? $currentUser : null;
		}

		if (!Loader::includeModule('security'))
		{
			return null;
		}

		$userId = (new DeferredSkipDeviceConnectAccess())->getDeferredUserId($signedUserId);

		return $userId === null ? null : $this->getIntranetUserById($userId);
	}

	protected function getIntranetUserById(int $userId): ?User
	{
		return (new UserRepository())->getUserById($userId);
	}

	private function canAccessContactConfirmation(
		User $user,
		?string $flowId,
		?string $signedUserId,
		string $channel,
	): bool
	{
		if ($this->getCurrentIntranetUser() !== null)
		{
			return $this->canCurrentUserEditOtp($user);
		}

		$deferredUser = $this->getSkipDeviceConnectUser($signedUserId);
		if ($deferredUser === null || $deferredUser->getId() !== $user->getId() || $flowId === null)
		{
			return false;
		}

		if (
			!$this->isSkipDeviceConnectAllowed()
			|| !$this->isSecurityModuleAvailable()
			|| !$this->isPushOtpAvailable()
			|| !$this->createPersonalOtp($user)->canSkipDeviceConnect()
		)
		{
			return false;
		}

		$skipDeviceConnect = $this->createSkipDeviceConnectService();
		if ($skipDeviceConnect->isCompleted($user, $flowId))
		{
			return false;
		}

		return in_array(
			$channel,
			$skipDeviceConnect->getAvailableChannels($user, $flowId),
			true,
		);
	}

	protected function createVerifyPhoneService(User $user): VerifyPhoneService
	{
		return VerifyPhoneService::createFor2Fa($user);
	}

	protected function requirePhoneConfirmation(User $user): void
	{
		PersonalMobilePush::createByUser($user)->requirePhoneConfirmation();
	}

	protected function clearPhoneConfirmationRequirement(User $user): void
	{
		PersonalMobilePush::createByUser($user)->clearPhoneConfirmationRequirement();
	}

	protected function canCurrentUserEditOtp(User $user): bool
	{
		return (new UserPermission($user))->canEdit();
	}

	protected function isSkipDeviceConnectAllowed(): bool
	{
		return Option::get('intranet', 'allow_skip_device_connect', 'Y') === 'Y';
	}

	protected function isPushOtpAvailable(): bool
	{
		return \Bitrix\Security\Mfa\Otp::isPushPossible();
	}

	protected function isSecurityModuleAvailable(): bool
	{
		return Loader::includeModule('security');
	}

	protected function createPersonalOtp(User $user): PersonalOtp
	{
		return new PersonalOtp($user);
	}

	protected function sendSkipDeviceConnectEvent(string $event): void
	{
		(new AnalyticsEvent($event, 'skip_device_connect', 'security'))->send();
	}

	protected function isPhoneChannelAvailable(User $user): bool
	{
		return (new VerifyPhoneService($user))->canSendSms();
	}

	protected function isEmailChannelAvailable(User $user): bool
	{
		return (new VerifyEmailService($user))->canSendEmail();
	}

	protected function canLoginByChannel(User $user, string $channel): bool
	{
		return match ($channel)
		{
			SkipDeviceConnect::CHANNEL_PHONE => (new VerifyPhoneService($user))->canLoginBySms(),
			SkipDeviceConnect::CHANNEL_EMAIL => (new VerifyEmailService($user))->canLoginByEmail(),
			default => false,
		};
	}

	public function setupPushOtpAction(User $user, string $secret, string $sync1, array $initParams = []): bool
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		try
		{
			$permission = new UserPermission($user);

			if (!$permission->canEdit())
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
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		try
		{
			$permission = new UserPermission($user);

			if (!$permission->canActivate())
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
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		try
		{
			$permission = new UserPermission($user);

			if (!$permission->canDeactivate())
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			$dayListProvider = new DayListProvider();
			$rawDays = $this->getRequest()->get('days');

			if ($rawDays !== null && !$dayListProvider->isAllowedRawPeriod($user, $rawDays))
			{
				$this->addError(new Error("Invalid period"));

				return false;
			}

			if (!$dayListProvider->isAllowedPeriod($user, $days))
			{
				$this->addError(new Error("Invalid period"));

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
	 * @throws CommandValidationException
	 * @throws CommandException
	 */
	public function sendRequestRecoverAccessAction(): void
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return;
		}

		$user = $this->getOtpSessionUser();
		if (!$user)
		{
			return;
		}

		if (!$this->canUseRecoverAccessRequest($user))
		{
			return;
		}

		(new SendRequestRecoverAccessCommand($user))->run();
	}

	public function isRequestRecoverAccessSentAction(): ?bool
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return null;
		}

		$user = $this->getOtpSessionUser();
		if (!$user || !$this->canUseRecoverAccessRequest($user))
		{
			return null;
		}

		return (new RecoverAccessRequestService())->isSent($user);
	}

	public function canSendRequestRecoverAccessAction(): ?bool
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return null;
		}

		$user = $this->getOtpSessionUser();
		if (!$user || !$this->canUseRecoverAccessRequest($user))
		{
			return null;
		}

		return (new RecoverAccessRequestService())->canSend($user);
	}

	public function getRequestRecoverAccessStatusAction(): array
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		$user = $this->getOtpSessionUser();
		if (!$user || !$this->canUseRecoverAccessRequest($user))
		{
			return [];
		}

		$recoverAccessRequestService = new RecoverAccessRequestService();

		return $recoverAccessRequestService->getStatus($user);
	}

	protected function getOtpSessionUser(): ?User
	{
		if (!Loader::includeModule('security'))
		{
			return null;
		}

		$otpParams = \Bitrix\Security\Mfa\Otp::getDeferredParams();
		$userId = (int)($otpParams['USER_ID'] ?? 0);

		if ($userId <= 0)
		{
			$this->addError(new Error('User not found'));

			return null;
		}

		$user = (new UserRepository())->getUserById($userId);
		if (!$user)
		{
			$this->addError(new Error('User not found'));

			return null;
		}

		return $user;
	}

	private function canUseRecoverAccessRequest(User $user): bool
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId > 0 && !(new UserPermission($user))->canEdit())
		{
			$this->addError(new Error('No rights'));

			return false;
		}

		return true;
	}

	/**
	 * @throws ArgumentException
	 */
	public function changeAuthPhoneAction(
		User $user,
		Phone $phoneNumber,
		?string $flowId = null,
		?string $signedUserId = null,
	): bool
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		try
		{
			if (!$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_PHONE))
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			$verifyPhoneService = $this->createVerifyPhoneService($user);
			$verifyPhoneService->changeAuthPhone($phoneNumber);

			if (!$verifyPhoneService->isConfirmed($phoneNumber))
			{
				$this->requirePhoneConfirmation($user);
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

	public function sendConfirmationCodeAction(
		User $user,
		string $smsTemplate = 'SMS_USER_CONFIRM_NUMBER',
		?string $flowId = null,
		?string $signedUserId = null,
	): ?array
	{
		if (!$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_PHONE))
		{
			$this->addError(new Error("No rights"));

			return [];
		}

		try
		{
			return $this->createVerifyPhoneService($user)->sendCodeByTemplate($smsTemplate);
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
	public function confirmationPhoneNumberAction(
		User $user,
		string $code,
		?string $flowId = null,
		?string $signedUserId = null,
	): ?bool
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		try
		{
			if (!$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_PHONE))
			{
				$this->addError(new Error("No rights"));

				return false;
			}

			$confirmation = function () use ($user, $code): bool
			{
				$confirmed = $this->createVerifyPhoneService($user)->confirmPhoneNumber($code);
				if ($confirmed)
				{
					$this->clearPhoneConfirmationRequirement($user);
				}

				return $confirmed;
			};

			return $flowId === null
				? $confirmation()
				: $this->runSkipDeviceConnectConfirmation(
					$user,
					$flowId,
					SkipDeviceConnect::CHANNEL_PHONE,
					$confirmation,
				)
			;
		}
		catch (SendException $exception)
		{
			$this->addErrors($exception->getErrors()->getValues());

			return false;
		}
	}

	public function changeAuthEmailAction(Email $email, ?string $flowId = null, ?string $signedUserId = null)
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		if ($this->getCurrentIntranetUser() !== null)
		{
			return $this->forward(PushOtp::class, 'sendConfirmationEmail', ['email' => $email->getValue()]);
		}

		return $this->sendDeferredEmailConfirmation($email, $flowId, $signedUserId);
	}

	public function sendEmailConfirmationAction(Email $email, ?string $flowId = null, ?string $signedUserId = null)
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		if ($this->getCurrentIntranetUser() !== null)
		{
			return $this->forward(PushOtp::class, 'sendConfirmationEmail', ['email' => $email->getValue()]);
		}

		return $this->sendDeferredEmailConfirmation($email, $flowId, $signedUserId);
	}

	private function sendDeferredEmailConfirmation(Email $email, ?string $flowId, ?string $signedUserId): ?array
	{
		$user = $this->getSkipDeviceConnectUser($signedUserId);
		if (
			$user === null
			|| $flowId === null
			|| !$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_EMAIL)
			|| !$this->isEmailChannelAvailable($user)
		)
		{
			$this->addError(new Error('No rights', 'SKIP_DEVICE_CONNECT_ACCESS_DENIED'));

			return null;
		}

		return $this->forward(PushOtp::class, 'sendConfirmationEmail', ['email' => $email->getValue()]);
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function confirmationEmailAction(
		string $code,
		string $signedData,
		?string $flowId = null,
		?string $signedUserId = null,
	)
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return false;
		}

		$user = $this->getCurrentIntranetUser();
		if ($user === null)
		{
			$user = $this->getSkipDeviceConnectUser($signedUserId);
			if (
				$user === null
				|| $flowId === null
				|| !$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_EMAIL)
				|| !$this->isEmailChannelAvailable($user)
			)
			{
				$this->addError(new Error('No rights', 'SKIP_DEVICE_CONNECT_ACCESS_DENIED'));

				return false;
			}

			$confirmation = function () use ($user, $code, $signedData, $flowId, $signedUserId): bool {
				if (
					!$this->canAccessContactConfirmation($user, $flowId, $signedUserId, SkipDeviceConnect::CHANNEL_EMAIL)
					|| !$this->isEmailChannelAvailable($user)
				)
				{
					$this->addError(new Error('No rights', 'SKIP_DEVICE_CONNECT_ACCESS_DENIED'));

					return false;
				}
				$this->forward(PushOtp::class, 'confirmEmail', [
					'code' => $code,
					'signedData' => $signedData,
				]);
				if ($this->hasErrors())
				{
					return false;
				}

				return $this->canLoginByChannel($user, SkipDeviceConnect::CHANNEL_EMAIL);
			};

			return $this->runSkipDeviceConnectConfirmation(
				$user,
				$flowId,
				SkipDeviceConnect::CHANNEL_EMAIL,
				$confirmation,
			);
		}

		$confirmation = function () use ($code, $signedData): bool
		{
			$this->forward(PushOtp::class, 'confirmEmail', [
				'code' => $code,
				'signedData' => $signedData,
			]);

			return !$this->hasErrors();
		};

		return $user !== null && $flowId !== null
			? $this->runSkipDeviceConnectConfirmation(
				$user,
				$flowId,
				SkipDeviceConnect::CHANNEL_EMAIL,
				$confirmation,
			)
			: $confirmation()
		;
	}

	private function runSkipDeviceConnectConfirmation(
		User $user,
		string $flowId,
		string $channel,
		callable $confirmation,
	): bool
	{
		$skipDeviceConnect = $this->createSkipDeviceConnectService();
		try
		{
			return $skipDeviceConnect->runLocked(
				$user,
				function () use ($skipDeviceConnect, $user, $flowId, $channel, $confirmation): bool {
					if (
						$skipDeviceConnect->isCompleted($user, $flowId)
						|| !in_array($channel, $skipDeviceConnect->getAvailableChannels($user, $flowId), true)
					)
					{
						$this->addError(new Error('Skip device connection intent was not found', 'SKIP_DEVICE_CONNECT_NO_INTENT'));

						return false;
					}

					if (!$confirmation())
					{
						return false;
					}

					$skipDeviceConnect->confirmChannel($user, $flowId, $channel);

					return true;
				},
			);
		}
		catch (SkipDeviceConnectLockException)
		{
			$this->addError(new Error('Unable to confirm OTP setup channel', 'SKIP_DEVICE_CONNECT_LOCK_FAILED'));

			return false;
		}
		catch (StorageException)
		{
			$this->addError(new Error('Unable to save OTP setup channel', 'SKIP_DEVICE_CONNECT_STORAGE_FAILED'));

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

		return $this->forward(PushOtp::class, 'sendSms');
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function sendAuthEmailAction(): ?array
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		return $this->forward(PushOtp::class, 'sendEmail');
	}

	/**
	 * @throws SystemException|LoaderException
	 */
	public function sendMobilePushAction(string $channelTag): mixed
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		return $this->forward(PushOtp::class, 'sendMobilePush', [
			'channelTag' => $channelTag,
		]);
	}

	public function setLegacyOtpAllowedAction(int $userId, string $allowed): bool
	{
		$user = (new UserRepository())->getUserById($userId);

		if (!$user)
		{
			$this->addError(new Error('User not found'));

			return false;
		}

		$permission = new UserPermission($user);

		if (!$permission->canCurrentUserEdit())
		{
			$this->addError(new Error('No rights'));

			return false;
		}

		$result = (new SetLegacyOtpAllowedCommand($user, $allowed === 'Y'))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 */
	public function getConfigAction(User $user): array
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return [];
		}

		$permission = new UserPermission($user);

		if (!$permission->canEdit())
		{
			$this->addError(new Error("No rights"));

			return [];
		}

		return (new PersonalOtp($user))->getOtpConfig();
	}

	public function logoutAllAction(
		User $user,
		string $keepTrustedDevice = 'N',
		?string $backurl = null,
	): ?array
	{
		if (!$user->isCurrent())
		{
			$this->addError(new Error("No rights"));

			return null;
		}

		$logoutService = $this->createLogoutService($user);

		if ($keepTrustedDevice === 'Y')
		{
			$logoutResult = $logoutService->logoutAllExceptTrustedDeviceWithResult();
		}
		else
		{
			$logoutResult = $logoutService->logoutAllWithResult();
		}

		if (!$logoutResult->isSuccess())
		{
			$this->addErrors($logoutResult->getErrors());

			return null;
		}

		return [
			'networkLogoutUrl' => $this->createNetworkLogoutUrlProvider()->getUrl(
				(int)$user->getId(),
				$backurl,
			),
		];
	}

	public function isTrustedAction(string $secretFingerprint): ?array
	{
		if ($this->getScope() !== Controller::SCOPE_REST)
		{
			$this->addError(new Error('The method is available over REST only', 'REST_ONLY'));

			return null;
		}

		if (preg_match('/^[0-9a-fA-F]{64}$/D', $secretFingerprint) !== 1)
		{
			$this->addError(new Error('Malformed secret fingerprint', 'INVALID_FINGERPRINT'));

			return null;
		}

		$user = $this->getCurrentIntranetUser();
		if ($user === null)
		{
			$this->addError(new Error('Authentication is required', 'NOT_AUTHENTICATED'));

			return null;
		}

		$result = $this->createTrustedDeviceCheckService()
			->check($user, $secretFingerprint)
		;

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'isTrusted' => $result->getData()['isTrusted'],
		];
	}

	protected function createTrustedDeviceCheckService(): TrustedDeviceCheckService
	{
		return new TrustedDeviceCheckService();
	}

	protected function createLogoutService(User $user): LogoutService
	{
		return new LogoutService($user);
	}

	protected function createNetworkLogoutUrlProvider(): NetworkLogoutUrlProvider
	{
		return new NetworkLogoutUrlProvider();
	}

	public function resetOtpSessionAction(): void
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return;
		}

		\Bitrix\Security\Mfa\Otp::setDeferredParams(null);
	}

	public function generateRecoveryCodesFileAction(): ?HttpResponse
	{
		if (!Loader::includeModule('security'))
		{
			$this->addError(new Error('Module Security is not installed'));

			return null;
		}

		$user = new User((int)CurrentUser::get()->getId());
		$recoveryCodes = new RecoveryCodes($user);
		$codes = $recoveryCodes->getList(true, true);

		if (empty($codes))
		{
			$this->addError(new Error('No recovery codes available'));

			return null;
		}

		$response = $recoveryCodes->prepareFileContent($codes);

		$httpResponse = new HttpResponse();
		$httpResponse->setContent($response);
		$headers = new HttpHeaders([
			'Content-Type' => 'text/plain',
			'Content-Disposition' => 'attachment; filename="recovery_codes.txt"',
			'Content-Transfer-Encoding' => 'binary',
			'Content-Length' => (string)strlen($response),
		]);
		$httpResponse->setHeaders($headers);

		return $httpResponse;
	}
}
