<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox\Avatar;

use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internals\MailboxAvatarResourceTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class AvatarService
{
	public const ERROR_VERSION_CONFLICT = 'MAIL_AVATAR_VERSION_CONFLICT';

	private readonly UrlManager $urlManager;

	public function __construct(
		private readonly AvatarResourceService $resources = new AvatarResourceService(),
		?UrlManager $urlManager = null,
	)
	{
		$this->urlManager = $urlManager ?? UrlManager::getInstance();
	}

	/** The callback owns its transaction and returns only after commit or rollback. */
	public function save(int $mailboxId, AvatarChange $change, callable $save): Result
	{
		if (!Feature::isMailboxAvatarAvailable())
		{
			return $this->failure(AvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		$resourceId = null;
		$committed = false;
		try
		{
			$mailbox = MailboxTable::query()
				->setSelect(['ID', 'USER_ID', 'ACTIVE', 'SERVER_TYPE', 'AVATAR_REVISION'])
				->where('ID', $mailboxId)->fetch();
			$access = $this->checkChange($mailbox ?: [], $change);
			if (!$access->isSuccess())
			{
				return $access;
			}
			if ($change->operation === AvatarChange::OPERATION_REPLACE)
			{
				$prepared = $this->resources->prepare($mailboxId, $change->uploadToken);
				if (!$prepared->isSuccess())
				{
					return self::localizeErrors($prepared);
				}
				$resourceId = $prepared->getData()['resourceId'];
			}
			$result = $save($resourceId);
			$committed = $result->isSuccess();

			return self::localizeErrors($result);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return self::localizeErrors($this->failure(AvatarResourceService::ERROR_SAVE_FAILED));
		}
		finally
		{
			if (!$committed && $resourceId !== null)
			{
				try
				{
					$this->resources->cleanup($resourceId, discardPending: true);
				}
				catch (\Throwable $exception)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				}
			}
		}
	}

	public function checkChange(array $mailbox, AvatarChange $change): Result
	{
		$mailboxId = (int)($mailbox['ID'] ?? 0);
		if ($mailboxId <= 0 || (int)CurrentUser::get()?->getId() <= 0
			|| (Feature::isMailboxGridAvailable()
				&& MailboxModel::createFromId($mailboxId)->getOwnerId() !== (int)$mailbox['USER_ID'])
			|| !MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			return self::localizeErrors($this->failure(AvatarResourceService::ERROR_ACCESS_DENIED));
		}
		if (!$this->isSupported($mailbox))
		{
			return self::localizeErrors($this->failure(AvatarResourceService::ERROR_NOT_SUPPORTED));
		}
		if ((int)$mailbox['AVATAR_REVISION'] !== $change->expectedRevision)
		{
			return self::localizeErrors($this->failure(self::ERROR_VERSION_CONFLICT));
		}

		return new Result();
	}

	/** Call after existing settings writes and locks, within their transaction. */
	public function applyWithinTransaction(
		array $mailbox,
		AvatarChange $change,
		?int $resourceId,
		array &$afterCommit,
	): Result
	{
		$checked = $this->checkChange($mailbox, $change);
		if (!$checked->isSuccess())
		{
			return $checked;
		}
		$applied = $this->resources->applyWithinTransaction($mailbox, $change, $resourceId);
		if (!$applied->isSuccess())
		{
			return self::localizeErrors($applied);
		}
		$mailboxId = (int)$mailbox['ID'];
		$previousId = $applied->getData()['previousResourceId'];
		$revision = $applied->getData()['revision'];
		$hasAvatar = $change->operation === AvatarChange::OPERATION_REPLACE;
		$afterCommit[] = static function () use ($mailboxId): void {
			MailboxTable::cleanRuntimeFullRowCachesByMailboxId($mailboxId);
			MailboxTable::getEntity()->cleanCache();
		};
		if ($previousId > 0)
		{
			$afterCommit[] = fn () => $this->resources->cleanup($previousId);
		}

		return (new Result())->setData(['avatar' => [
			'mailboxId' => $mailboxId,
			'revision' => $revision,
			'hasAvatar' => $hasAvatar,
			'url' => $hasAvatar ? $this->urlManager->create('mail.api.mailboxavatar.view', [
				'mailboxId' => $mailboxId,
				'revision' => $revision,
			])->getUri() : null,
			'supported' => true,
			'canEdit' => true,
		]]);
	}

	public static function localizeErrors(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}
		Loc::loadMessages(dirname(__DIR__, 4) . '/controller/mailboxavatar.php');
		$localized = (new Result())->setData($result->getData());
		foreach ($result->getErrors() as $error)
		{
			$code = $error->getCode();
			$localized->addError(is_string($code) && str_starts_with($code, 'MAIL_AVATAR_')
				? new Error(Loc::getMessage($code) ?? $error->getMessage(), $code, $error->getCustomData())
				: $error);
		}

		return $localized;
	}

	public function get(int $mailboxId): Result
	{
		if (!Feature::isMailboxAvatarAvailable())
		{
			return $this->failure(AvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		try
		{
			$mailboxResult = $this->getReadableMailbox($mailboxId);
			if (!$mailboxResult->isSuccess())
			{
				return $mailboxResult;
			}

			$mailbox = $mailboxResult->getData()['mailbox'];
			$supported = $this->isSupported($mailbox);
			$hasAvatar = (int)$mailbox['AVATAR_RESOURCE_ID'] > 0;
			$revision = (int)$mailbox['AVATAR_REVISION'];
			$file = $supported && $hasAvatar ? $this->findImageMetadata($mailbox) : null;
			$url = null;
			if ($file !== null && $this->isImageStored($file))
			{
				$url = $this->urlManager->create('mail.api.mailboxavatar.view', [
					'mailboxId' => $mailboxId,
					'revision' => $revision,
				])->getUri();
			}

			return (new Result())->setData([
				'mailboxId' => $mailboxId,
				'revision' => $revision,
				'hasAvatar' => $hasAvatar,
				'url' => $url,
				'supported' => $supported,
				'canEdit' => $supported && MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId),
			]);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(AvatarResourceService::ERROR_SAVE_FAILED);
		}
	}

	/** @return array<int, array{mailboxId: int, revision: int, url: ?string}> */
	public function getDisplayData(array $mailboxIds): array
	{
		// The image behind the URL is served only while the feature is on, so a disabled feature gives
		// out no URL at all instead of one every consumer would request in vain.
		if (!Feature::isMailboxAvatarAvailable())
		{
			return [];
		}

		$mailboxIds = array_values(array_unique(array_filter(
			array_map('intval', $mailboxIds),
			static fn (int $mailboxId): bool => $mailboxId > 0,
		)));
		$userId = (int)CurrentUser::get()?->getId();
		if ($mailboxIds === [] || $userId <= 0)
		{
			return [];
		}

		$models = MailboxModel::createFromIds($mailboxIds);
		if ($models === [])
		{
			return [];
		}

		$accessibleMailboxIds = MailboxAccessController::getInstance($userId)->withMailboxBatch(
			$models,
			static function () use ($models): array {
				$accessibleMailboxIds = [];
				foreach (array_keys($models) as $mailboxId)
				{
					if (MailboxAccess::hasCurrentUserAnyAccessToMailbox((int)$mailboxId))
					{
						$accessibleMailboxIds[] = (int)$mailboxId;
					}
				}

				return $accessibleMailboxIds;
			},
		);
		if ($accessibleMailboxIds === [])
		{
			return [];
		}

		$mailboxRows = MailboxTable::query()
			->setSelect(['ID', 'AVATAR_RESOURCE_ID', 'AVATAR_REVISION'])
			->whereIn('ID', $accessibleMailboxIds)
			->where('ACTIVE', 'Y')
			->where('SERVER_TYPE', 'imap')
			->fetchAll()
		;
		$mailboxes = [];
		$resourceIds = [];
		foreach ($mailboxRows as $mailbox)
		{
			$mailboxId = (int)$mailbox['ID'];
			$resourceId = (int)$mailbox['AVATAR_RESOURCE_ID'];
			$mailboxes[$mailboxId] = $mailbox;
			if ($resourceId > 0)
			{
				$resourceIds[] = $resourceId;
			}
		}

		$resources = [];
		if ($resourceIds !== [])
		{
			$resourceRows = MailboxAvatarResourceTable::query()
				->setSelect(['ID', 'MAILBOX_ID'])
				->whereIn('ID', array_values(array_unique($resourceIds)))
				->fetchAll()
			;
			foreach ($resourceRows as $resource)
			{
				$resources[(int)$resource['ID']] = (int)$resource['MAILBOX_ID'];
			}
		}

		$result = [];
		foreach ($mailboxIds as $mailboxId)
		{
			if (!isset($mailboxes[$mailboxId]))
			{
				continue;
			}

			$mailbox = $mailboxes[$mailboxId];
			$resourceId = (int)$mailbox['AVATAR_RESOURCE_ID'];
			$revision = (int)$mailbox['AVATAR_REVISION'];
			$hasResource = $resourceId > 0 && ($resources[$resourceId] ?? 0) === $mailboxId;
			$result[$mailboxId] = [
				'mailboxId' => $mailboxId,
				'revision' => $revision,
				'url' => $hasResource
					? $this->urlManager->create('mail.api.mailboxavatar.view', [
						'mailboxId' => $mailboxId,
						'revision' => $revision,
					])->getUri()
					: null,
			];
		}

		return $result;
	}

	public function getImage(int $mailboxId, int $revision): Result
	{
		if (!Feature::isMailboxAvatarAvailable())
		{
			return $this->failure(AvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		try
		{
			$mailboxResult = $this->getReadableMailbox($mailboxId);
			if (!$mailboxResult->isSuccess())
			{
				return $mailboxResult;
			}

			$mailbox = $mailboxResult->getData()['mailbox'];
			if (!$this->isSupported($mailbox))
			{
				return $this->failure(AvatarResourceService::ERROR_NOT_SUPPORTED);
			}
			if ($revision < 0 || $revision !== (int)$mailbox['AVATAR_REVISION'])
			{
				return $this->failure(self::ERROR_VERSION_CONFLICT);
			}

			$file = $this->findImageMetadata($mailbox);
			$image = $file === null ? null : $this->openImageFile($file);
			if ($image === null)
			{
				return $this->failure(AvatarImageProcessor::ERROR_IMAGE_INVALID);
			}

			$content = @file_get_contents($image['path'], false, null, 0, AvatarImageProcessor::MAX_FILE_SIZE + 1);
			if (
				!is_string($content)
				|| strlen($content) !== $image['size']
				|| strlen($content) > AvatarImageProcessor::MAX_FILE_SIZE
				|| !str_starts_with($content, "\x89PNG\r\n\x1a\n")
			)
			{
				return $this->failure(AvatarImageProcessor::ERROR_IMAGE_INVALID);
			}

			return (new Result())->setData(['content' => $content]);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(AvatarImageProcessor::ERROR_IMAGE_INVALID);
		}
	}

	private function getReadableMailbox(int $mailboxId): Result
	{
		if (
			$mailboxId <= 0
			|| (int)CurrentUser::get()?->getId() <= 0
			|| !MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId)
		)
		{
			return $this->failure(AvatarResourceService::ERROR_ACCESS_DENIED);
		}

		$mailbox = MailboxTable::query()
			->setSelect(['ID', 'ACTIVE', 'SERVER_TYPE', 'AVATAR_RESOURCE_ID', 'AVATAR_REVISION'])
			->where('ID', $mailboxId)
			->fetch()
		;
		if (!$mailbox)
		{
			return $this->failure(AvatarResourceService::ERROR_ACCESS_DENIED);
		}

		return (new Result())->setData(['mailbox' => $mailbox]);
	}

	private function isSupported(array $mailbox): bool
	{
		return Feature::isMailboxAvatarAvailable()
			&& $mailbox['ACTIVE'] === 'Y'
			&& $mailbox['SERVER_TYPE'] === 'imap'
		;
	}

	private function findImageMetadata(array $mailbox): ?array
	{
		$resourceId = (int)$mailbox['AVATAR_RESOURCE_ID'];
		if ($resourceId <= 0)
		{
			return null;
		}

		try
		{
			$resource = MailboxAvatarResourceTable::query()
				->setSelect(['FILE_ID'])
				->where('ID', $resourceId)
				->where('MAILBOX_ID', (int)$mailbox['ID'])
				->fetch()
			;
			$fileId = (int)($resource['FILE_ID'] ?? 0);
			$file = $fileId > 0 ? \CFile::getFileArray($fileId) : false;
			if (
				!is_array($file)
				|| (int)$file['ID'] !== $fileId
				|| $file['MODULE_ID'] !== 'mail'
				|| $file['CONTENT_TYPE'] !== 'image/png'
				|| (int)$file['FILE_SIZE'] <= 0
				|| (int)$file['FILE_SIZE'] > AvatarImageProcessor::MAX_FILE_SIZE
			)
			{
				return null;
			}

			return $file;
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return null;
		}
	}

	private function isImageStored(array $file): bool
	{
		try
		{
			$handlerId = (int)($file['HANDLER_ID'] ?? 0);
			if ($handlerId > 0)
			{
				return Loader::includeModule('clouds') && $this->isBucketObjectStored($handlerId, $file);
			}

			$path = self::localPath($file);
			clearstatcache(true, $path);

			return is_file($path) && is_readable($path) && @filesize($path) === (int)$file['FILE_SIZE'];
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return false;
		}
	}

	/**
	 * FileExists asks the service for the object headers without moving the body, and answers with
	 * one of three things: the length of the object, true when the object is there but the answer
	 * carries no usable length (200 without Content-Length, or a partial 206), false when it is not.
	 */
	private function isBucketObjectStored(int $handlerId, array $file): bool
	{
		$bucket = new \CCloudStorageBucket($handlerId);
		if (!$bucket->Init())
		{
			return false;
		}

		$storedSize = $bucket->FileExists('/' . $file['SUBDIR'] . '/' . $file['FILE_NAME']);
		if (is_numeric($storedSize))
		{
			return (int)$storedSize === (int)$file['FILE_SIZE'];
		}

		// Without a length nothing contradicts the row; the bytes are checked by the protected view.
		return $storedSize !== false;
	}

	private function openImageFile(array $file): ?array
	{
		$fileArray = \CFile::makeFileArray((int)$file['ID']);
		$path = is_array($fileArray) ? ($fileArray['tmp_name'] ?? null) : null;
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path))
		{
			return null;
		}

		clearstatcache(true, $path);
		$size = @filesize($path);
		if (!is_int($size) || $size !== (int)$file['FILE_SIZE'] || $size > AvatarImageProcessor::MAX_FILE_SIZE)
		{
			return null;
		}

		return ['path' => $path, 'size' => $size];
	}

	private static function localPath(array $file): string
	{
		$path = Application::getDocumentRoot() . '/' . Option::get('main', 'upload_dir', 'upload')
			. '/' . $file['SUBDIR'] . '/' . $file['FILE_NAME'];

		return \CBXVirtualIo::GetInstance()->GetPhysicalName(preg_replace('#[\\\\/]+#', '/', $path));
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
