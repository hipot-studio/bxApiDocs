<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\Disk\Dto\LargeAttachmentResult;
use Bitrix\Mail\Internal\Service\LargeAttachment\LargeAttachmentService;
use Bitrix\Intranet;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;

/**
 * Class LargeAttachment
 * Compose-side handling of over-limit mail attachments: converting an upload set into a Disk link
 * (API-01) and deleting a previously uploaded set (API-02).
 *
 * Thin controller: input validation, tariff gate and mailbox ownership only; all business logic lives
 * in {@see LargeAttachmentService}. Errors are surfaced as typed string codes the client routes by.
 *
 * @package Bitrix\Mail\Controller
 */
class LargeAttachment extends Controller
{
	public const ERROR_TARIFF_UNAVAILABLE = 'MAIL_LA_TARIFF_UNAVAILABLE';
	public const ERROR_ACCESS_DENIED = 'MAIL_LA_ACCESS_DENIED';
	public const ERROR_INVALID_CONTEXT = 'MAIL_LA_INVALID_CONTEXT';

	private const CONTEXT_MAIL = 'mail';
	private const CONTEXT_CRM = 'crm';
	private const ALLOWED_CONTEXTS = [
		self::CONTEXT_MAIL,
		self::CONTEXT_CRM,
	];

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([
				Main\Engine\ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
			]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * API-01: places the given Disk files into the sender's system folder and returns the serviceable
	 * link (DTO-01). The conversion itself is done by the service; the action only gates access.
	 *
	 * @param int[] $fileIds Disk\File object ids from the uploader.
	 * @param int|null $mailboxId Source mailbox (null for an external compose).
	 * @param string $context Trigger context.
	 * @param string|null $replacementToken Existing set token when only new files should be appended.
	 */
	public function convertAction(
		array $fileIds,
		?int $mailboxId = null,
		string $context = self::CONTEXT_MAIL,
		?string $replacementToken = null,
	): array
	{
		if (!in_array($context, self::ALLOWED_CONTEXTS, true))
		{
			$this->addError(new Error('Large attachment context is invalid.', self::ERROR_INVALID_CONTEXT));

			return [];
		}

		if (!LicenseManager::isLargeAttachmentAutoUploadEnabled())
		{
			$this->addError(new Error(
				'Large attachment auto-upload is not available on the current plan.',
				self::ERROR_TARIFF_UNAVAILABLE,
			));

			return [];
		}

		if ($mailboxId !== null && $mailboxId > 0 && !MailboxAccess::hasCurrentUserAccessToMailbox($mailboxId, true))
		{
			$this->addError(new Error('Access to the mailbox is denied.', self::ERROR_ACCESS_DENIED));

			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		$fileIds = array_values(array_filter(array_map('intval', $fileIds)));

		$result = LargeAttachmentService::convert($userId, $fileIds, $replacementToken);
		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return [];
		}

		/** @var LargeAttachmentResult $uploaded */
		$uploaded = $result->getData()[LargeAttachmentService::RESULT_KEY];

		return [
			'publicUrl' => $uploaded->publicUrl,
			'token' => $uploaded->token,
			'fileIds' => $uploaded->fileIds,
			// folderName is surfaced by the real Disk adapter (phase P4); the stub never reaches this branch.
			'folderName' => '',
		];
	}

	/**
	 * API-02: deletes a previously uploaded set addressed by its opaque token. Ownership is enforced by
	 * the service through the authenticated user id (only the sender's own service file is removed).
	 */
	public function deleteUploadedAction(string $token): array
	{
		$userId = (int)CurrentUser::get()->getId();

		$result = LargeAttachmentService::deleteUploaded($userId, $token);
		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return ['deleted' => false];
		}

		return [
			'deleted' => (bool)($result->getData()[LargeAttachmentService::DELETED_KEY] ?? false),
		];
	}

	public function finalizeReplacementAction(string $previousToken, string $currentToken): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$result = LargeAttachmentService::finalizeReplacement($userId, $previousToken, $currentToken);
		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return ['finalized' => false];
		}

		return ['finalized' => true];
	}
}
