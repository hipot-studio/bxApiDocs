<?php

declare(strict_types=1);

namespace Bitrix\Mail\FileUploader;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Service\Avatar\AvatarUploadContract;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarResourceService;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\FileUploader\CanUploadResult;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadRequest;

final class MailboxAvatarUploaderController extends UploaderController
{
	private AvatarUploadContract $uploadContract;

	public function __construct(int|array $mailboxId)
	{
		if (is_array($mailboxId))
		{
			$mailboxId = $mailboxId['mailboxId'] ?? 0;
		}
		if (is_string($mailboxId) && preg_match('/^[0-9]+$/D', $mailboxId) === 1)
		{
			$mailboxId = filter_var($mailboxId, FILTER_VALIDATE_INT) ?: 0;
		}

		parent::__construct([
			'mailboxId' => is_int($mailboxId) && $mailboxId > 0 ? $mailboxId : 0,
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
		$this->uploadContract = new AvatarUploadContract();
	}

	public function isAvailable(): bool
	{
		return $this->getAccessError() === null;
	}

	public function getConfiguration(): Configuration
	{
		return $this->uploadContract->getConfiguration();
	}

	public function canUpload(?UploadRequest $uploadRequest = null): bool|CanUploadResult
	{
		$code = $this->getAccessError() ?? $this->uploadContract->validate($uploadRequest);
		if ($code === null)
		{
			return true;
		}

		Loc::loadMessages(dirname(__DIR__) . '/controller/mailboxavatar.php');

		return (new CanUploadResult())->addError(new Error(Loc::getMessage($code), $code));
	}

	public function canView(): bool
	{
		return $this->isAvailable();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		// Persistent avatar files are owned by the mailbox resource, never by this temporary uploader.
	}

	public function canRemove(): bool
	{
		return false;
	}

	private function getAccessError(): ?string
	{
		try
		{
			if (!Feature::isMailboxAvatarAvailable())
			{
				return AvatarResourceService::ERROR_NOT_SUPPORTED;
			}

			$mailboxId = $this->getOption('mailboxId');
			$userId = (int)CurrentUser::get()?->getId();
			if (
				$mailboxId <= 0
				|| $userId <= 0
				|| $userId !== $this->getOption('userId')
				|| !MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId)
			)
			{
				return AvatarResourceService::ERROR_ACCESS_DENIED;
			}

			$mailbox = MailboxTable::query()
				->setSelect(['ID', 'ACTIVE', 'SERVER_TYPE'])
				->where('ID', $mailboxId)
				->fetch()
			;
			if (!$mailbox)
			{
				return AvatarResourceService::ERROR_ACCESS_DENIED;
			}

			return $mailbox['ACTIVE'] === 'Y' && $mailbox['SERVER_TYPE'] === 'imap'
				? null
				: AvatarResourceService::ERROR_NOT_SUPPORTED
			;
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return AvatarResourceService::ERROR_ACCESS_DENIED;
		}
	}
}
