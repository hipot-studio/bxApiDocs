<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\ValidatePassword;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

readonly class ExternalLinkValidatePasswordCommandHandler
{
	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param ExternalLinkPasswordService $externalLinkPasswordService
	 */
	public function __construct(
		protected ExternalLinkProvider $externalLinkProvider,
		protected ExternalLinkPasswordService $externalLinkPasswordService,
	)
	{
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

		$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($command->fileId);

		if (!$externalLink instanceof ExternalLink)
		{
			return $this->getError(ExternalLinkValidatePasswordError::ExternalLinkNotFound);
		}

		if (!$this->externalLinkPasswordService->validateAndConfirm($externalLink, $command->password))
		{
			return $this->getError(ExternalLinkValidatePasswordError::InvalidPassword);
		}

		return null;
	}

	/**
	 * @param ExternalLinkValidatePasswordError $code
	 * @return Error
	 */
	protected function getError(ExternalLinkValidatePasswordError $code): Error
	{
		$codeString = $code->value;
		$locMessageCode = "DISK_EXTERNAL_LINK_VALIDATE_PASSWORD_$codeString";

		return new Error(
			message: Loc::getMessage($locMessageCode) ?? '',
			code: $codeString,
		);
	}
}
