<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink;

use Bitrix\Disk\Internal\Service\ExternalLink\MailAttachmentExternalLinkService;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final readonly class CreateMailAttachmentLinkCommandHandler
{
	/**
	 * @param int $currentUserId user of the process, zero when nobody is authorized; the portal builds a
	 *   new handler on every resolve (`disk/.settings.php`), so the value is the current user of the
	 *   moment of the resolve and not of the start of the process, which can authorize somebody else in
	 *   between
	 */
	public function __construct(
		private MailAttachmentExternalLinkService $service,
		private int $currentUserId,
	)
	{
	}

	public function __invoke(CreateMailAttachmentLinkCommand $command): Result
	{
		if (!$this->isAllowedToActForTheUser($command))
		{
			return (new Result())->addError($this->buildError(CreateMailAttachmentLinkError::ObjectOutOfScope));
		}

		$result = $this->service->createLink($command->userId, $command->objectId, $command->deathTime);
		if ($result->isSuccess())
		{
			return $result;
		}

		return (new Result())->addError($this->translateError($result->getError()));
	}

	/**
	 * The userId of the command is a server side value {@see CreateMailAttachmentLinkCommand}, and it can
	 * only be the current user: a link on behalf of somebody else would publish an attachment of theirs.
	 * A caller without a current user has to declare the system context on the command, because there is
	 * nothing to compare the userId with, and an unauthorized request looks exactly the same way.
	 */
	private function isAllowedToActForTheUser(CreateMailAttachmentLinkCommand $command): bool
	{
		if ($command->bySystem)
		{
			return true;
		}

		return $this->currentUserId > 0 && $this->currentUserId === $command->userId;
	}

	/**
	 * The service reports technical messages in English, the public contract needs a translated one.
	 * The diagnostic payload of the service travels along: the caller gets one and the same code for
	 * every reason of a refusal, and without the payload nothing is left of a rare failure.
	 */
	private function translateError(?Error $serviceError): Error
	{
		$error = CreateMailAttachmentLinkError::tryFrom((string)$serviceError?->getCode())
			?? CreateMailAttachmentLinkError::LinkCreationFailed
		;

		return $this->buildError($error, $serviceError?->getCustomData());
	}

	private function buildError(CreateMailAttachmentLinkError $error, mixed $customData = null): Error
	{
		return new Error(
			message: Loc::getMessage('DISK_CREATE_MAIL_ATTACHMENT_LINK_ERROR_' . $error->value) ?? '',
			code: $error->value,
			customData: $customData,
		);
	}
}
