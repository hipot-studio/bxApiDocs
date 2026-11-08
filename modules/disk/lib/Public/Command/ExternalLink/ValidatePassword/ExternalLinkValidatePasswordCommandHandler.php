<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\ValidatePassword;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordProtectionService;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\ExternalLink\PasswordProtectionResult;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

readonly class ExternalLinkValidatePasswordCommandHandler
{
	protected ExternalLinkPasswordProtectionService $externalLinkPasswordProtectionService;

	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param ExternalLinkPasswordService $externalLinkPasswordService
	 */
	public function __construct(
		protected ExternalLinkProvider $externalLinkProvider,
		protected ExternalLinkPasswordService $externalLinkPasswordService,
		?ExternalLinkPasswordProtectionService $externalLinkPasswordProtectionService = null,
	)
	{
		$this->externalLinkPasswordProtectionService =
			$externalLinkPasswordProtectionService
			?? new ExternalLinkPasswordProtectionService($this->externalLinkPasswordService)
		;
	}

	/**
	 * @param ExternalLinkValidatePasswordCommand $command
	 * @return Error|null
	 */
	public function __invoke(ExternalLinkValidatePasswordCommand $command): ?Error
	{
		if (!is_string($command->password) || $command->password === '')
		{
			return $this->getError(ExternalLinkValidatePasswordError::InvalidPassword);
		}

		$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($command->objectId);

		if (!$externalLink instanceof ExternalLink)
		{
			return $this->getError(ExternalLinkValidatePasswordError::ExternalLinkNotFound);
		}

		$result = $this->getExternalLinkPasswordProtectionService()->checkPassword(
			$externalLink,
			$command->password,
			$command->sourceIp,
			$command->captchaSid,
			$command->captchaWord,
		);

		if ($this->isUnsafePasswordProtectionResult($result))
		{
			return $this->getError(ExternalLinkValidatePasswordError::Forbidden);
		}

		if ($result->status !== PasswordProtectionResult::STATUS_SUCCESS)
		{
			return $this->getPasswordProtectionError($result);
		}

		return null;
	}

	private function getExternalLinkPasswordProtectionService(): ExternalLinkPasswordProtectionService
	{
		return $this->externalLinkPasswordProtectionService;
	}

	private function isUnsafePasswordProtectionResult(PasswordProtectionResult $result): bool
	{
		if ($result->captchaRequired && $result->captchaSid === null)
		{
			return true;
		}

		return (
			$result->status === PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED
			&& $result->passwordChecked
		);
	}

	private function getPasswordProtectionError(PasswordProtectionResult $result): Error
	{
		return match ($result->status)
		{
			PasswordProtectionResult::STATUS_CAPTCHA_REQUIRED => $this->getError(
				ExternalLinkValidatePasswordError::CaptchaRequired,
				$result,
			),
			PasswordProtectionResult::STATUS_CAPTCHA_INVALID => $this->getError(
				ExternalLinkValidatePasswordError::CaptchaInvalid,
				$result,
			),
			default => $this->getError(ExternalLinkValidatePasswordError::InvalidPassword, $result),
		};
	}

	/**
	 * @param ExternalLinkValidatePasswordError $code
	 * @return Error
	 */
	protected function getError(ExternalLinkValidatePasswordError $code, ?PasswordProtectionResult $result = null): Error
	{
		$codeString = $code->value;
		$locMessageCode = "DISK_EXTERNAL_LINK_VALIDATE_PASSWORD_$codeString";

		return new Error(
			message: Loc::getMessage($locMessageCode) ?? '',
			code: $codeString,
			customData: $result === null ? null : [
				'status' => $result->status,
				'captchaRequired' => $result->captchaRequired,
				'captchaSid' => $result->captchaSid,
			],
		);
	}
}
