<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internals\SenderAvatarResourceTable;
use Bitrix\Mail\Internals\SenderAvatarTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class SenderAvatarService
{
	public const ERROR_VERSION_CONFLICT = 'MAIL_SENDER_AVATAR_VERSION_CONFLICT';

	private \Closure $canManage;
	private \Closure $currentUserId;

	public function __construct(
		private readonly ExternalSenderResolver $resolver,
		private readonly SenderAvatarResourceService $resources = new SenderAvatarResourceService(),
		?\Closure $canManage = null,
		?\Closure $currentUserId = null,
	)
	{
		$this->canManage = $canManage ?? MailAccess::hasCurrentUserAccessToSenderAvatarManagement(...);
		$this->currentUserId = $currentUserId ?? static fn(): int => (int)CurrentUser::get()?->getId();
	}

	public function save(int $messageId, SenderAvatarChange $change, string $accessToken = ''): Result
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			return $this->failure(SenderAvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		$userId = ($this->currentUserId)();
		$resolved = $this->resolver->resolve($messageId, $userId, $accessToken, requireCurrentEmployeeStatus: true);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		if (!($this->canManage)())
		{
			return $this->failure(SenderAvatarResourceService::ERROR_ACCESS_DENIED);
		}
		$email = $resolved->getData()['email'];
		$resourceId = null;
		$committed = false;
		$transactionStarted = false;
		try
		{
			if ($change->operation === SenderAvatarChange::OPERATION_REPLACE)
			{
				// The revision is compared before the image is processed. A repeat with a revision that
				// is already stale gets the same refusal from this read as it would get under the lock
				// below, and neither the processing nor a resource of its own is spent on it.
				if ($this->readRevision($email) !== $change->expectedRevision)
				{
					return $this->failure(self::ERROR_VERSION_CONFLICT);
				}

				$prepared = $this->resources->prepare($email, $messageId, (string)$change->uploadToken);
				if (!$prepared->isSuccess())
				{
					return $prepared;
				}
				$resourceId = (int)$prepared->getData()['resourceId'];
			}

			$this->createSettingIfMissing($email);
			$connection = Application::getConnection();
			$connection->startTransaction();
			$transactionStarted = true;
			$setting = $this->lockSetting($email);
			if ($setting === null)
			{
				$this->rollbackTransaction();
				$transactionStarted = false;

				return $this->failure(SenderAvatarResourceService::ERROR_SAVE_FAILED);
			}
			if ((int)$setting['REVISION'] !== $change->expectedRevision)
			{
				$connection->commitTransaction();
				$transactionStarted = false;

				return $this->failure(self::ERROR_VERSION_CONFLICT);
			}

			$previousResourceId = (int)$setting['RESOURCE_ID'];
			$revision = (int)$setting['REVISION'] + 1;
			$updatePointer = static function (?int $newResourceId) use ($setting, $revision): Result {
				$updated = SenderAvatarTable::update((int)$setting['ID'], [
					'RESOURCE_ID' => $newResourceId,
					'REVISION' => $revision,
					'DATE_MODIFY' => new DateTime(),
				]);

				return $updated->isSuccess()
					? new Result()
					: (new Result())->addError(new Error(
						SenderAvatarResourceService::ERROR_SAVE_FAILED,
						SenderAvatarResourceService::ERROR_SAVE_FAILED,
					))
				;
			};
			$applied = $change->operation === SenderAvatarChange::OPERATION_REPLACE
				? $this->resources->attach($email, (int)$resourceId, $previousResourceId, $userId, $updatePointer)
				: $this->resources->detach($email, $previousResourceId, $updatePointer)
			;
			if (!$applied->isSuccess())
			{
				$this->rollbackTransaction();
				$transactionStarted = false;

				return $applied;
			}
			$connection->commitTransaction();
			$transactionStarted = false;
			$committed = true;

			return (new Result())->setData([
				'email' => $email,
				'revision' => $revision,
				'hasAvatar' => $change->operation === SenderAvatarChange::OPERATION_REPLACE,
			]);
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				try
				{
					$this->rollbackTransaction();
				}
				catch (\Throwable $rollbackException)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($rollbackException);
				}
			}
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(SenderAvatarResourceService::ERROR_SAVE_FAILED);
		}
		finally
		{
			// The choice of the file survives a refusal in the temporary upload, not here: the caller
			// reads the current revision and repeats the change with the same upload token, and that
			// repeat prepares the resource anew. Keeping this one would only pile up rows and files.
			if (!$committed && $resourceId !== null)
			{
				try
				{
					$this->resources->cleanup($resourceId, true);
				}
				catch (\Throwable $exception)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				}
			}
		}
	}

	public function get(int $messageId, string $accessToken = ''): Result
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			return $this->failure(SenderAvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		$resolved = $this->resolver->resolve($messageId, ($this->currentUserId)(), $accessToken);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		$email = $resolved->getData()['email'];
		$setting = SenderAvatarTable::query()
			->setSelect(['RESOURCE_ID', 'REVISION'])
			->where('EMAIL_NORMALIZED', $email)
			->fetch()
		;

		return (new Result())->setData([
			'email' => $email,
			'revision' => (int)($setting['REVISION'] ?? 0),
			'hasAvatar' => (int)($setting['RESOURCE_ID'] ?? 0) > 0,
			'canManage' => (bool)($this->canManage)(),
		]);
	}

	public function getImage(int $messageId, int $revision, string $accessToken = ''): Result
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			return $this->failure(SenderAvatarResourceService::ERROR_NOT_SUPPORTED);
		}

		$resolved = $this->resolver->resolve($messageId, ($this->currentUserId)(), $accessToken);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		$email = $resolved->getData()['email'];
		$setting = SenderAvatarTable::query()
			->setSelect(['RESOURCE_ID', 'REVISION'])
			->where('EMAIL_NORMALIZED', $email)
			->fetch()
		;
		if (!$setting || $revision < 0 || (int)$setting['REVISION'] !== $revision)
		{
			return $this->failure(self::ERROR_VERSION_CONFLICT);
		}
		$resource = SenderAvatarResourceTable::query()
			->setSelect(['FILE_ID'])
			->where('ID', (int)$setting['RESOURCE_ID'])
			->where('EMAIL_NORMALIZED', $email)
			->where('STATE', SenderAvatarResourceTable::STATE_ATTACHED)
			->fetch()
		;
		$fileId = (int)($resource['FILE_ID'] ?? 0);
		$metadata = $fileId > 0 ? \CFile::getFileArray($fileId) : false;
		if (
			!is_array($metadata)
			|| (int)$metadata['ID'] !== $fileId
			|| $metadata['MODULE_ID'] !== 'mail'
			|| $metadata['CONTENT_TYPE'] !== 'image/png'
			|| (int)$metadata['FILE_SIZE'] <= 0
			|| (int)$metadata['FILE_SIZE'] > AvatarImageProcessor::MAX_FILE_SIZE
		)
		{
			return $this->failure(AvatarImageProcessor::ERROR_IMAGE_INVALID);
		}

		$file = \CFile::makeFileArray($fileId);
		$path = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
		$readable = $path !== '' && is_file($path) && is_readable($path);
		if ($readable)
		{
			clearstatcache(true, $path);
		}
		$size = $readable ? @filesize($path) : false;
		$content = $readable
			? @file_get_contents($path, false, null, 0, AvatarImageProcessor::MAX_FILE_SIZE + 1)
			: false
		;
		if (
			!is_int($size)
			|| $size !== (int)$metadata['FILE_SIZE']
			|| !is_string($content)
			|| strlen($content) !== $size
			|| strlen($content) > AvatarImageProcessor::MAX_FILE_SIZE
			|| !str_starts_with($content, "\x89PNG\r\n\x1a\n")
		)
		{
			return $this->failure(AvatarImageProcessor::ERROR_IMAGE_INVALID);
		}

		return (new Result())->setData(['content' => $content]);
	}

	/**
	 * An address without a row has the same revision as an address whose photo was removed, so the
	 * caller of a first assignment and the caller of a repeated one both expect zero here.
	 */
	private function readRevision(string $email): int
	{
		$setting = SenderAvatarTable::query()
			->setSelect(['REVISION'])
			->where('EMAIL_NORMALIZED', $email)
			->fetch()
		;

		return (int)($setting['REVISION'] ?? 0);
	}

	/**
	 * Creates the row of the address before the save opens its transaction. Two first assignments of
	 * one address both find no row, and the losing insert breaks on the unique EMAIL_NORMALIZED -
	 * inside the transaction that error would take the whole transaction down, on PostgreSQL it
	 * could not continue at all, and the save would answer a failure instead of a conflict. Here the
	 * loser reads the committed row under the lock and stops on the revision check. A row without a
	 * resource means the same as no row, so creating it changes nothing the reader sees.
	 */
	private function createSettingIfMissing(string $email): void
	{
		$existing = SenderAvatarTable::query()
			->setSelect(['ID'])
			->where('EMAIL_NORMALIZED', $email)
			->fetch()
		;
		if ($existing)
		{
			return;
		}

		try
		{
			SenderAvatarTable::add(['EMAIL_NORMALIZED' => $email]);
		}
		catch (DuplicateEntryException)
		{
			// The concurrent save created the row first.
		}
	}

	private function lockSetting(string $email): ?array
	{
		$row = Application::getConnection()->query((new SqlExpression(
			'SELECT * FROM ?# WHERE EMAIL_NORMALIZED = ?s FOR UPDATE',
			SenderAvatarTable::getTableName(),
			$email,
		))->compile())->fetch();

		return $row ?: null;
	}

	private function rollbackTransaction(): void
	{
		try
		{
			Application::getConnection()->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
