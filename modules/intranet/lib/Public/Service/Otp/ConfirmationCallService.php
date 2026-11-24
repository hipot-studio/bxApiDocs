<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationTerminalStatus;
use Bitrix\Intranet\Internal\Service\Otp\ConfirmationCallCoordinator;
use Bitrix\Intranet\Internal\Service\Otp\ConfirmationCallAnalytics;
use Bitrix\Intranet\Internal\Service\Otp\ConfirmationCallAvailability;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\Attempt;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\ResponseResult;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\Status;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UuidGenerator;
use Bitrix\Security\Mfa\OtpType;

class ConfirmationCallService
{
	public const ERROR_UNAVAILABLE = 'unavailable';
	public const ERROR_COMPLETION_FAILED = 'completion_failed';
	public const TERMINAL_STATUS_CONFIRMED_BY_CALL = 'confirmed_by_call';
	private const ERROR_FORBIDDEN = 'forbidden';
	private const CONFIRMATION_CALL_SESSION_BINDING_KEY = 'intranet.otp.confirmation_call.session_binding';
	private const CONFIRMATION_CALL_LOGIN_NONCE_KEY = 'INTRANET_CONFIRMATION_CALL_NONCE';
	private const CONFIRMATION_CALL_ATTEMPT_KEY_PREFIX = 'intranet.otp.confirmation_call.attempt.';

	public function __construct(
		private readonly ?ConfirmationCallCoordinator $coordinator = null,
		private readonly ?ConfirmationCallAnalytics $analytics = null,
	)
	{
	}

	/** Starts a call bound to the stored setup phone. */
	public function startSetup(User $user, Phone $phone): Result
	{
		return $this->startSetupResponse($user, $phone);
	}

	/** Starts a call bound to the deferred login. */
	public function startLogin(): Result
	{
		return $this->startLoginResponse();
	}

	/** Reads the call status for the stored setup phone. */
	public function getSetupStatus(User $user, string $confirmationCallId): Result
	{
		return $this->getSetupStatusResponse($user, $confirmationCallId);
	}

	/** Reads the call status for the deferred login. */
	public function getLoginStatus(string $confirmationCallId): Result
	{
		return $this->getLoginStatusResponse($confirmationCallId);
	}

	/** Confirms the stored setup phone using the call. */
	public function completeSetup(User $user, string $confirmationCallId): Result
	{
		return $this->completeSetupResponse($user, $confirmationCallId);
	}

	/** Completes the deferred login using the call. */
	public function completeLogin(
		string $confirmationCallId,
		string $rememberDevice = 'N',
		string $captchaWord = '',
		string $captchaSid = '',
	): Result
	{
		return $this->completeLoginResponse($confirmationCallId, $rememberDevice, $captchaWord, $captchaSid);
	}

	/** Returns whether setup can use the call channel. */
	public function isSetupAvailable(int $userId): bool
	{
		return $this->createConfirmationCallAvailability()->forSetup($userId);
	}

	/** Records a rejected setup request without creating an attempt. */
	public function noticeForbiddenSetup(): void
	{
		$this->createConfirmationCallAnalytics()->failed(PhoneConfirmationScenario::Setup, self::ERROR_FORBIDDEN);
	}

	/** Records switching from a call to SMS. */
	public function noticeSmsUse(bool $login = false): void
	{
		$this->noticeSmsChannelUse($login ? PhoneConfirmationScenario::Login : PhoneConfirmationScenario::Setup);
	}

	/** Closes a pending setup call after the SMS factor was confirmed. */
	public function closeAfterSmsConfirmation(User $user): void
	{
		$this->closeConfirmationCallAfterSmsConfirmation($user);
	}

	private function startSetupResponse(User $user, Phone $phoneNumber): Result
	{
		$contextResult = $this->resolveSetupConfirmationCallContext($user, $phoneNumber);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->respondWithStartedConfirmationCall(
			PhoneConfirmationScenario::Setup,
			$context['phoneRevision'],
			$this->createConfirmationCallCoordinator()->start(
				PhoneConfirmationScenario::Setup,
				$this->resolveConfirmationCallSessionBinding(),
				(int)$user->getId(),
				$context['phoneE164'],
				$context['phoneRevision'],
			),
		);
	}

	private function getSetupStatusResponse(
		User $user,
		string $confirmationCallId,
	): Result
	{
		$contextResult = $this->resolveSetupConfirmationCallContext($user);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->normalizeConfirmationCallResult(
			$this->createConfirmationCallCoordinator()->getStatus(
				PhoneConfirmationScenario::Setup,
				$this->resolveConfirmationCallSessionBinding(),
				(int)$user->getId(),
				$context['phoneRevision'],
				$confirmationCallId,
			),
		);
	}

	private function completeSetupResponse(User $user, string $confirmationCallId): Result
	{
		$contextResult = $this->resolveSetupConfirmationCallContext($user);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->respondWithCompletedConfirmationCall(
			PhoneConfirmationScenario::Setup,
			$this->createConfirmationCallCoordinator()->completeSetup(
				$this->resolveConfirmationCallSessionBinding(),
				$user,
				$context['phoneRevision'],
				$confirmationCallId,
			),
		);
	}

	/** The phone and revision are server-owned; request revisions never participate in the binding. */
	private function resolveSetupConfirmationCallContext(User $user, ?Phone $requestedPhone = null): Result
	{
		$result = new Result();
		$phoneE164 = $this->readAuthPhoneOf($user);
		if ($phoneE164 === null)
		{
			$result->addError(new Error(
				'Confirmation call is unavailable.',
				ConfirmationCallCoordinator::ERROR_UNAVAILABLE,
			));

			return $result;
		}

		if ($requestedPhone !== null && !$this->isAuthPhoneOfUser($phoneE164, $requestedPhone))
		{
			$result->addError(new Error('No rights for the phone number', self::ERROR_FORBIDDEN));

			return $result;
		}

		$result->setData([
			'phoneE164' => $phoneE164,
			'phoneRevision' => $this->calculateConfirmationCallPhoneRevision($phoneE164),
		]);

		return $result;
	}

	private function readAuthPhoneOf(User $user): ?string
	{
		$phoneE164 = (string)$user->getAuthPhoneNumber();

		return $phoneE164 === '' ? null : $phoneE164;
	}

	private function isAuthPhoneOfUser(string $boundPhoneE164, Phone $requestedPhone): bool
	{
		return hash_equals(
			$boundPhoneE164,
			UserPhoneAuthTable::normalizePhoneNumber($requestedPhone->defaultFormat()),
		);
	}

	private function startLoginResponse(): Result
	{
		$contextResult = $this->resolveLoginConfirmationCallContext();
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->respondWithStartedConfirmationCall(
			PhoneConfirmationScenario::Login,
			$context['phoneRevision'],
			$this->createConfirmationCallCoordinator()->start(
				PhoneConfirmationScenario::Login,
				$this->resolveLoginConfirmationCallSessionBinding(),
				$context['userId'],
				$context['phoneE164'],
				$context['phoneRevision'],
			),
		);
	}

	private function getLoginStatusResponse(string $confirmationCallId): Result
	{
		$contextResult = $this->resolveLoginConfirmationCallContext();
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->normalizeConfirmationCallResult(
			$this->createConfirmationCallCoordinator()->getStatus(
				PhoneConfirmationScenario::Login,
				$this->resolveLoginConfirmationCallSessionBinding(),
				$context['userId'],
				$context['phoneRevision'],
				$confirmationCallId,
			),
		);
	}

	private function completeLoginResponse(
		string $confirmationCallId,
		string $rememberDevice = 'N',
		string $captchaWord = '',
		string $captchaSid = '',
	): Result
	{
		$contextResult = $this->resolveLoginConfirmationCallContext();
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}
		$context = $contextResult->getData();

		return $this->respondWithCompletedConfirmationCall(
			PhoneConfirmationScenario::Login,
			$this->createConfirmationCallCoordinator()->completeLogin(
				$this->resolveLoginConfirmationCallSessionBinding(),
				$context['userId'],
				$context['phoneRevision'],
				$confirmationCallId,
				$rememberDevice === 'Y',
				$captchaWord,
				$captchaSid,
			),
		);
	}

	private function resolveLoginConfirmationCallContext(): Result
	{
		$result = new Result();
		$userId = $this->resolveDeferredLoginUserId();
		if ($userId <= 0)
		{
			$result->addError(new Error('There is no deferred login to confirm.', self::ERROR_FORBIDDEN));

			return $result;
		}

		$phoneE164 = $this->findConfirmedAuthPhone($userId);
		if ($phoneE164 === null)
		{
			$result->addError(new Error(
				'Confirmation call is unavailable.',
				ConfirmationCallCoordinator::ERROR_UNAVAILABLE,
			));

			return $result;
		}

		$result->setData([
			'userId' => $userId,
			'phoneE164' => $phoneE164,
			'phoneRevision' => $this->calculateConfirmationCallPhoneRevision($phoneE164),
		]);

		return $result;
	}

	private function resolveDeferredLoginUserId(): int
	{
		if (!Loader::includeModule('security'))
		{
			return 0;
		}

		$deferredParams = \Bitrix\Security\Mfa\Otp::getDeferredParams();
		if (($deferredParams['OTP_TYPE'] ?? null) !== OtpType::Push->value)
		{
			return 0;
		}

		return (int)($deferredParams['USER_ID'] ?? 0);
	}

	private function findConfirmedAuthPhone(int $userId): ?string
	{
		return $this->findAuthPhone($userId, ['=CONFIRMED' => 'Y']);
	}

	private function findAuthPhone(int $userId, array $extraFilter = []): ?string
	{
		$row = UserPhoneAuthTable::getRow([
			'select' => ['PHONE_NUMBER'],
			'filter' => ['=USER_ID' => $userId] + $extraFilter,
		]);

		$phoneNumber = $row === null ? '' : (string)$row['PHONE_NUMBER'];

		return $phoneNumber === '' ? null : $phoneNumber;
	}

	private function calculateConfirmationCallPhoneRevision(string $phoneE164): int
	{
		return (int)hexdec(substr(hash('sha256', $phoneE164), 0, 7)) + 1;
	}

	protected function createConfirmationCallCoordinator(): ConfirmationCallCoordinator
	{
		return $this->coordinator ?? new ConfirmationCallCoordinator();
	}

	protected function createConfirmationCallAvailability(): ConfirmationCallAvailability
	{
		return new ConfirmationCallAvailability();
	}

	/** Deferred params own the nonce: another first factor or reset invalidates previous attempts. */
	private function resolveLoginConfirmationCallSessionBinding(): string
	{
		$deferredParams = \Bitrix\Security\Mfa\Otp::getDeferredParams();
		$nonce = $deferredParams[self::CONFIRMATION_CALL_LOGIN_NONCE_KEY] ?? null;

		if (!is_string($nonce) || $nonce === '')
		{
			$nonce = UuidGenerator::generateV4();
			$deferredParams[self::CONFIRMATION_CALL_LOGIN_NONCE_KEY] = $nonce;
			\Bitrix\Security\Mfa\Otp::setDeferredParams($deferredParams);
		}

		return hash('sha256', $this->resolveConfirmationCallSessionBinding() . '.' . $nonce);
	}

	/** Keeps the opaque session binding stable across session ID regeneration. */
	private function resolveConfirmationCallSessionBinding(): string
	{
		$session = Application::getInstance()->getKernelSession();
		$binding = $session[self::CONFIRMATION_CALL_SESSION_BINDING_KEY] ?? null;

		if (!is_string($binding) || $binding === '')
		{
			$binding = UuidGenerator::generateV4();
			$session[self::CONFIRMATION_CALL_SESSION_BINDING_KEY] = $binding;
		}

		return $binding;
	}

	private function respondWithStartedConfirmationCall(
		PhoneConfirmationScenario $scenario,
		int $phoneRevision,
		Result $result,
	): Result
	{
		$result = $this->normalizeConfirmationCallResult($result);
		$analytics = $this->createConfirmationCallAnalytics();

		if (!$result->isSuccess())
		{
			$analytics->failed($scenario, $this->describeConfirmationCallFailure($result));

			return $result;
		}

		$attempt = $result instanceof ResponseResult ? $result->getPayload() : null;
		if ($attempt instanceof Attempt)
		{
			$this->rememberConfirmationCallAttempt($scenario, $phoneRevision);
			$analytics->started($scenario);
		}

		return $result;
	}

	private function respondWithCompletedConfirmationCall(
		PhoneConfirmationScenario $scenario,
		Result $result,
	): Result
	{
		$result = $this->normalizeConfirmationCallResult($result);
		$analytics = $this->createConfirmationCallAnalytics();

		if (!$result->isSuccess())
		{
			$analytics->failed($scenario, $this->describeConfirmationCallFailure($result));

			return $result;
		}

		$delay = $this->measureConfirmationCallDelay($scenario);
		$terminalStatus = $result instanceof ResponseResult ? $result->getPayload() : null;
		if (
			$delay !== null
			&& $terminalStatus === PhoneConfirmationTerminalStatus::ConfirmedByCall
		)
		{
			$this->forgetConfirmationCallAttempt($scenario);
			$analytics->confirmed($scenario, $delay);
		}

		return $result;
	}

	private function describeConfirmationCallFailure(Result $result): string
	{
		foreach ($result->getErrors() as $error)
		{
			return (string)$error->getCode();
		}

		return ConfirmationCallCoordinator::ERROR_UNAVAILABLE;
	}

	private function noticeSmsChannelUse(PhoneConfirmationScenario $scenario): void
	{
		if ($this->readConfirmationCallAttempt($scenario) === null)
		{
			return;
		}

		$this->createConfirmationCallAnalytics()->switchedToSms($scenario);
	}

	private function closeConfirmationCallAfterSmsConfirmation(User $user): void
	{
		$attempt = $this->readConfirmationCallAttempt(PhoneConfirmationScenario::Setup);
		if ($attempt === null)
		{
			return;
		}

		try
		{
			$this->createConfirmationCallCoordinator()->closeAfterSmsConfirmation(
				PhoneConfirmationScenario::Setup,
				$this->resolveConfirmationCallSessionBinding(),
				(int)$user->getId(),
				$attempt['phoneRevision'],
			);
		}
		catch (\Throwable)
		{
		}
		finally
		{
			$this->forgetConfirmationCallAttempt(PhoneConfirmationScenario::Setup);
		}
	}

	private function rememberConfirmationCallAttempt(PhoneConfirmationScenario $scenario, int $phoneRevision): void
	{
		$session = Application::getInstance()->getKernelSession();
		$session[self::CONFIRMATION_CALL_ATTEMPT_KEY_PREFIX . $scenario->value] = [
			'phoneRevision' => $phoneRevision,
			'startedAt' => time(),
		];
	}

	private function readConfirmationCallAttempt(PhoneConfirmationScenario $scenario): ?array
	{
		$session = Application::getInstance()->getKernelSession();
		$attempt = $session[self::CONFIRMATION_CALL_ATTEMPT_KEY_PREFIX . $scenario->value] ?? null;

		if (!is_array($attempt) || !isset($attempt['phoneRevision'], $attempt['startedAt']))
		{
			return null;
		}

		return [
			'phoneRevision' => (int)$attempt['phoneRevision'],
			'startedAt' => (int)$attempt['startedAt'],
		];
	}

	private function forgetConfirmationCallAttempt(PhoneConfirmationScenario $scenario): void
	{
		$session = Application::getInstance()->getKernelSession();
		unset($session[self::CONFIRMATION_CALL_ATTEMPT_KEY_PREFIX . $scenario->value]);
	}

	private function measureConfirmationCallDelay(PhoneConfirmationScenario $scenario): ?int
	{
		$attempt = $this->readConfirmationCallAttempt($scenario);

		return $attempt === null ? null : time() - $attempt['startedAt'];
	}

	protected function createConfirmationCallAnalytics(): ConfirmationCallAnalytics
	{
		return $this->analytics ?? new ConfirmationCallAnalytics();
	}

	private function normalizeConfirmationCallResult(Result $result): Result
	{
		if ($result->isSuccess())
		{
			$data = $result->getData();
			$payload = $data[ConfirmationCallCoordinator::RESULT_KEY_ATTEMPT]
				?? $data[ConfirmationCallCoordinator::RESULT_KEY_STATUS]
				?? $data[ConfirmationCallCoordinator::RESULT_KEY_TERMINAL_STATUS]
				?? null;
			if (
				$payload instanceof Attempt
				|| $payload instanceof Status
				|| $payload instanceof PhoneConfirmationTerminalStatus
			)
			{
				return new ResponseResult($payload);
			}

			$normalized = new Result();
			$normalized->addError(new Error(
				'Confirmation call is unavailable.',
				self::ERROR_UNAVAILABLE,
			));

			return $normalized;
		}

		$normalized = new Result();
		foreach ($result->getErrors() as $error)
		{
			if ((string)$error->getCode() === ConfirmationCallCoordinator::ERROR_TOO_MANY_ATTEMPTS)
			{
				$normalized->addError(new Error(
					'Confirmation call completion was rejected.',
					ConfirmationCallCoordinator::ERROR_COMPLETION_FAILED,
				));

				continue;
			}

			$normalized->addError($error);
		}

		return $normalized;
	}
}
