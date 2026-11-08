<?php

declare(strict_types=1);

namespace Bitrix\Mail\FileUploader;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Internal\Service\Avatar\AvatarUploadContract;
use Bitrix\Mail\Internal\Service\Sender\Avatar\ExternalSenderResolver;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarResourceService;
use Bitrix\Mail\Public\Service\Sender\Avatar\MessageAccessCheckService;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;
use Bitrix\UI\FileUploader\CanUploadResult;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadRequest;

final class SenderAvatarUploaderController extends UploaderController
{
	private AvatarUploadContract $uploadContract;
	private ExternalSenderResolver $resolver;
	private string $messageAccessToken;

	public function __construct(int|array $options)
	{
		if (is_int($options))
		{
			$options = ['messageId' => $options];
		}
		$messageId = $options['messageId'] ?? 0;
		if (is_string($messageId) && preg_match('/^[0-9]+$/D', $messageId) === 1)
		{
			$messageId = filter_var($messageId, FILTER_VALIDATE_INT) ?: 0;
		}
		$this->messageAccessToken = is_string($options['messageAccessToken'] ?? null)
			? $options['messageAccessToken']
			: ''
		;

		parent::__construct([
			'messageId' => is_int($messageId) && $messageId > 0 ? $messageId : 0,
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
		$this->uploadContract = new AvatarUploadContract();
		$this->resolver = new ExternalSenderResolver(new MessageAccessCheckService());
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

		return (new CanUploadResult())->addError(new Error($code, $code));
	}

	public function canView(): bool
	{
		return $this->getAccessError() === null;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$this->getAccessError();
		// Persistent files belong to sender avatar resources and are never accepted as uploader input.
	}

	public function canRemove(): bool
	{
		$this->getAccessError();

		return false;
	}

	private function getAccessError(): ?string
	{
		try
		{
			if (!Feature::isSenderAvatarAvailable())
			{
				return SenderAvatarResourceService::ERROR_NOT_SUPPORTED;
			}

			$messageId = (int)$this->getOption('messageId');
			$userId = (int)CurrentUser::get()?->getId();
			if ($messageId <= 0 || $userId <= 0 || $userId !== $this->getOption('userId'))
			{
				return ExternalSenderResolver::ERROR_ACCESS_DENIED;
			}

			$resolved = $this->resolver->resolve(
				$messageId,
				$userId,
				$this->messageAccessToken,
				requireCurrentEmployeeStatus: true,
			);
			if (!$resolved->isSuccess())
			{
				return (string)($resolved->getError()?->getCode() ?: ExternalSenderResolver::ERROR_ACCESS_DENIED);
			}

			return MailAccess::hasCurrentUserAccessToSenderAvatarManagement()
				? null
				: SenderAvatarResourceService::ERROR_ACCESS_DENIED
			;
		}
		catch (\Throwable)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new SystemException('Sender avatar uploader access check failed.'),
			);

			return ExternalSenderResolver::ERROR_ACCESS_DENIED;
		}
	}
}
