<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Integration\Main\TrustedDeviceBindingProvider;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Public\Type\Otp\OtpChannel;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class ChannelRemovalService
{
	public const ERROR_NO_RIGHTS = 'CHANNEL_REMOVAL_NO_RIGHTS';
	public const ERROR_NO_TRUSTED_DEVICE = 'CHANNEL_REMOVAL_NO_TRUSTED_DEVICE';
	public const ERROR_CHANNEL_EMPTY = 'CHANNEL_REMOVAL_CHANNEL_EMPTY';
	public const ERROR_FAILED = 'CHANNEL_REMOVAL_FAILED';

	private const LOGGER_ID = 'intranet.otp.channel_removal';

	private readonly ChannelRemovalAuditLogger $auditLogger;

	/** @var \Closure(User): UserPermission */
	private readonly \Closure $permissionFactory;

	/** @var \Closure(User): TrustedDeviceBindingProvider */
	private readonly \Closure $trustedDeviceBindingFactory;

	/** @var array<int, bool> */
	private array $trustedDeviceBinding = [];

	public function __construct(
		?ChannelRemovalAuditLogger $auditLogger = null,
		?\Closure $permissionFactory = null,
		?\Closure $trustedDeviceBindingFactory = null,
	)
	{
		$this->auditLogger = $auditLogger ?? new ChannelRemovalAuditLogger();
		$this->permissionFactory = $permissionFactory
			?? static fn(User $user): UserPermission => new UserPermission($user)
		;
		$this->trustedDeviceBindingFactory = $trustedDeviceBindingFactory
			?? static fn(User $user): TrustedDeviceBindingProvider => new TrustedDeviceBindingProvider($user)
		;
	}

	public function removeChannel(User $user, OtpChannel $channel): Result
	{
		$access = $this->checkAccess($user, $channel);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$removal = $this->clearChannel($user, $channel);
		if (!$removal->isSuccess())
		{
			return $removal;
		}

		$this->runSecondaryStep(
			'audit',
			fn() => $this->auditLogger->logChannelRemoved($user, $channel),
		);

		return (new Result())->setData([
			'hasTrustedDeviceBinding' => $this->isTrustedDeviceBound($user),
		]);
	}

	private function checkAccess(User $user, OtpChannel $channel): Result
	{
		if (!($this->permissionFactory)($user)->canRemoveChannel())
		{
			return $this->error(self::ERROR_NO_RIGHTS, 'No rights to remove the channel');
		}

		if (!$this->isTrustedDeviceBound($user))
		{
			return $this->error(self::ERROR_NO_TRUSTED_DEVICE, 'No trusted device is bound');
		}

		if (!$this->isChannelFilled($user, $channel))
		{
			return $this->error(self::ERROR_CHANNEL_EMPTY, 'The channel is not set');
		}

		return new Result();
	}

	private function isChannelFilled(User $user, OtpChannel $channel): bool
	{
		$value = $channel === OtpChannel::Phone
			? $user->getAuthPhoneNumber()
			: (new PersonalOtp($user))->getBackupEmail()
		;

		return $value !== null && $value !== '';
	}

	private function clearChannel(User $user, OtpChannel $channel): Result
	{
		try
		{
			if ($channel === OtpChannel::Phone)
			{
				(new VerifyPhoneService($user))->removeAuthPhone();
			}
			else
			{
				(new PersonalOtp($user))->removeBackupEmail();
			}

			return new Result();
		}
		catch (UpdateFailedException $exception)
		{
			return $this->mapUpdateFailure($exception);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('clearChannel', $exception);

			return $this->error(self::ERROR_FAILED, 'Unable to remove the channel');
		}
	}

	private function mapUpdateFailure(UpdateFailedException $exception): Result
	{
		foreach ($exception->getErrors() as $error)
		{
			if ($error->getCode() === VerifyPhoneService::ERROR_PHONE_REQUIRED_BY_PORTAL)
			{
				return (new Result())->addError($error);
			}
		}

		$this->logFailure('clearChannel', $exception);

		return $this->error(self::ERROR_FAILED, 'Unable to remove the channel');
	}

	/**
	 * The audit log is secondary to the removal itself and must never fail it,
	 * but a silent loss would leave the incident with nothing to investigate.
	 */
	private function runSecondaryStep(string $step, \Closure $action): void
	{
		try
		{
			$action();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($step, $exception);
		}
	}

	private function logFailure(string $step, \Throwable $exception): void
	{
		(new LoggerFactory())->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)?->error(
			'Channel removal step {step} failed: {message}',
			[
				'step' => $step,
				'message' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Removing a channel never unbinds the device, so the answer holds for the whole request
	 * and both the access check and the response read it from here.
	 */
	private function isTrustedDeviceBound(User $user): bool
	{
		$userId = (int)$user->getId();

		return $this->trustedDeviceBinding[$userId]
			??= ($this->trustedDeviceBindingFactory)($user)->isBound()
		;
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
