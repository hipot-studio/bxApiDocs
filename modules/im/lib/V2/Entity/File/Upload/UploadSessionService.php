<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Im\Model\EO_FileUploadSession;
use Bitrix\Im\Model\EO_FileUploadSession_Collection;
use Bitrix\Im\Model\FileUploadSessionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;
use Bitrix\UI\FileUploader\TempFileBatchRemover;
use Bitrix\UI\FileUploader\TempFileTable;

/**
 * Lifetime of an upload session of the chunked upload door: open it, find the one of its owner,
 * forget it together with the bytes the ui circuit holds behind it.
 */
final class UploadSessionService
{
	/**
	 * Strictly below both lifetimes of the ui circuit: 12 hours for the bytes (TempFile) and
	 * 24 hours for the row (TempFileAgent).
	 */
	public const TTL_SECONDS = 21600;

	/**
	 * How many uploads one person may hold open at a time. A session costs a row and up to 100 MB of
	 * temporary bytes for six hours, and nothing else bounds how many of them a webhook opens: start takes
	 * no file with it and is cheap to repeat.
	 */
	public const OPEN_SESSION_LIMIT = 20;

	/** Prefix of every named lock of the door. */
	private const LOCK_PREFIX = 'im-upload-';

	/**
	 * The named lock of one session: everything that touches its row or the bytes behind it - append,
	 * finish, cancel and the cleanup - does its work under this one, and the name is computed here so that
	 * no caller of the door can name it a little differently and lock nothing at all.
	 */
	public static function lockOfSession(string $guid): string
	{
		return self::LOCK_PREFIX . $guid;
	}

	/** The named lock the starts of one caller stand in line for: the ceiling is counted under it. */
	public static function lockOfStartsOf(int $userId): string
	{
		return self::LOCK_PREFIX . 'start-' . $userId;
	}

	/**
	 * @return EO_FileUploadSession|null The session, or null when the row of it could not be written.
	 */
	public function open(
		int $chatId,
		int $userId,
		string $fileName,
		int $fileSize,
		string $mimeType
	): ?EO_FileUploadSession
	{
		$session = FileUploadSessionTable::createObject();
		$session
			->setGuid(UuidGenerator::generateV4())
			->setChatId($chatId)
			->setCreatedBy($userId)
			->setFileName($fileName)
			->setFileSize($fileSize)
			->setMimeType($mimeType)
			->setCreatedAt(new DateTime())
			->setExpiresAt((new DateTime())->add(self::TTL_SECONDS . ' seconds'))
		;

		return $this->persist($session) ? $session : null;
	}

	/**
	 * Sessions of this user that are still worth something: not finished either of the two ways and not
	 * run out. A finished one has already produced what it was opened for - a published message or a file
	 * of the chat folder - and an expired one is the cleanup's business, and neither holds anything back.
	 *
	 * The two columns here are {@see self::isFinished()} written as a filter, because a count of rows
	 * cannot ask a method; the two have to be changed together.
	 *
	 * Served by IX_B_IM_FILE_UPLOAD_SESSION_3 (CREATED_BY, MESSAGE_ID, DISK_FILE_ID, EXPIRES_AT).
	 * The three equalities exclude both finished states before the expiry range, keeping the count
	 * index-only and bounded by the number of open sessions.
	 */
	public function countOpen(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return (int)FileUploadSessionTable::getCount([
			'=CREATED_BY' => $userId,
			'>EXPIRES_AT' => new DateTime(),
			'=MESSAGE_ID' => null,
			'=DISK_FILE_ID' => null,
		]);
	}

	/**
	 * An unknown handle and a handle of another user answer alike. An expired session of its own
	 * owner is returned on purpose: for him it is "resume", not "start over".
	 */
	public function findOwn(string $uploadId, int $userId): ?EO_FileUploadSession
	{
		if ($uploadId === '' || $userId <= 0)
		{
			return null;
		}

		return FileUploadSessionTable::query()
			->setSelect(['*'])
			->where('GUID', $uploadId)
			->where('CREATED_BY', $userId)
			->setLimit(1)
			->fetchObject()
		;
	}

	public function isExpired(EO_FileUploadSession $session): bool
	{
		return $session->getExpiresAt()->getTimestamp() <= time();
	}

	/**
	 * Whether the session has already produced what it was opened for, by either of the two ways of
	 * finishing: a published message (finish) or a file of the chat folder with no message at all
	 * (finishToFolder). Terminal either way - a caller arriving at a finished session is answered by what
	 * the row says instead of being given a second file or a second message.
	 *
	 * 🔴 AND FOR THE PUBLISHING WAY THIS IS THE SAME ANSWER "MESSAGE_ID > 0" GAVE ON ITS OWN. DISK_FILE_ID
	 * is written by markPublished() alone, in one persist() together with MESSAGE_ID and only once the
	 * publication came off, so on a row of that flow the second half of the predicate never decides
	 * anything by itself. A row that nobody wrote the column into reads NULL - it is nullable with no
	 * default - and NULL is not greater than zero either, so the rows that stood in the table before the
	 * folder half existed are read exactly as they were.
	 */
	public function isFinished(EO_FileUploadSession $session): bool
	{
		return (int)$session->getMessageId() > 0 || (int)$session->getDiskFileId() > 0;
	}

	/** @return bool Whether the token really reached the row. */
	public function rememberToken(EO_FileUploadSession $session, string $token): bool
	{
		return $this->persist($session->setUploadToken($token));
	}

	/** @return bool Whether the identifiers of the published file really reached the row. */
	public function markPublished(EO_FileUploadSession $session, int $diskFileId, int $messageId): bool
	{
		return $this->persist(
			$session
				->setDiskFileId($diskFileId)
				->setMessageId($messageId)
		);
	}

	/**
	 * The file a session put into the chat folder without publishing anything. MESSAGE_ID is left empty on
	 * purpose: there is no message, and it is this column that tells the two ways of finishing apart.
	 *
	 * @return bool Whether the identifier of the registered file really reached the row.
	 */
	public function markRegistered(EO_FileUploadSession $session, int $diskFileId): bool
	{
		return $this->persist($session->setDiskFileId($diskFileId));
	}

	/**
	 * 🔴 save() OF THE ORM ANSWERS WITH A Result AND THROWS NOTHING, and a write that did not happen used to
	 * leave no trace at all: FILE_NAME is a required field, so a start with an empty name answered 200 with
	 * a handle no row stood behind, and every call made under that handle then answered CHAT_NOT_FOUND. Every
	 * write of this service therefore says whether it happened, and what could not be written goes to the log
	 * of the portal - a failed write of a row nobody is watching has no other way of being noticed.
	 */
	private function persist(EO_FileUploadSession $session): bool
	{
		$result = $session->save();
		if ($result->isSuccess())
		{
			return true;
		}

		Application::getInstance()->getExceptionHandler()->writeToLog(
			new \RuntimeException(
				'im chunked upload: the row of a session was not saved: ' . implode('; ', $result->getErrorMessages()),
			),
		);

		return false;
	}

	/**
	 * 🔴 THE ROW GOES EVEN WHEN THE BYTES WOULD NOT. A session whose temporary file cannot be freed - a
	 * bucket that answers with an error, a row of the circuit already damaged - used to carry the failure
	 * out of here, and since the cleanup takes the oldest EXPIRES_AT first, that one session would be the
	 * first of every run from then on and nothing behind it would ever be reached. The bytes have an owner
	 * of their own anyway: TempFileAgent of ui sweeps a temporary file a day old.
	 */
	public function drop(EO_FileUploadSession $session): void
	{
		try
		{
			$this->dropAcceptedBytes($session);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}

		$session->delete();
	}

	/**
	 * Sessions that have run out, oldest first.
	 *
	 * @param int[] $excludedIds Rows a previous pack of the same run could not remove. They stay first by
	 *        EXPIRES_AT and would otherwise be fetched again and again inside one run.
	 */
	public function findExpired(int $limit, array $excludedIds = []): EO_FileUploadSession_Collection
	{
		// Only what the removal needs: the bytes are addressed by the token, the row by its id and the
		// lock that has to be asked for before either of them is touched by the guid.
		$query = FileUploadSessionTable::query()
			->setSelect(['ID', 'GUID', 'UPLOAD_TOKEN'])
			->where('EXPIRES_AT', '<=', new DateTime())
			->setOrder(['EXPIRES_AT' => 'ASC'])
			->setLimit($limit)
		;

		if ($excludedIds !== [])
		{
			$query->whereNotIn('ID', $excludedIds);
		}

		return $query->fetchCollection();
	}

	/**
	 * Removes a whole pack at once: the bytes of every session in one batch of the ui circuit, then the
	 * rows in one statement. One session at a time meant a point SELECT and a delete of its own for each,
	 * and on a cloud portal a network DELETE per row inside the hit of whoever happened to run the agent.
	 *
	 * 🔴 AND NOT ONE SESSION IS TOUCHED WITHOUT ITS NAMED LOCK. append and finish do their whole work under
	 * it, and a finish that began before the session ran out is still going on when the hour of the agent
	 * comes: a removal in the middle of that publication either throws away bytes that were accepted or
	 * leaves the message standing with no row for a repeat of the finish to answer by. The lock is asked
	 * for with no timeout on purpose - a busy session is stepped over and reported back, not waited for:
	 * the agent runs inside somebody's hit, it has a budget, and standing in line behind a writer buys it
	 * nothing the next run cannot do.
	 *
	 * @param iterable<EO_FileUploadSession> $sessions
	 *
	 * @return int[] Ids of the sessions this call could not remove.
	 */
	public function dropPack(iterable $sessions): array
	{
		$connection = Application::getConnection();
		$ids = [];
		$guids = [];
		$busyIds = [];
		$heldLocks = [];

		foreach ($sessions as $session)
		{
			$lockName = self::lockOfSession((string)$session->getGuid());
			if (!$connection->lock($lockName, 0))
			{
				$busyIds[] = (int)$session->getId();

				continue;
			}

			$heldLocks[] = $lockName;
			$ids[] = (int)$session->getId();
			$guid = $this->findTempFileGuid($session);
			if ($guid !== '')
			{
				$guids[] = $guid;
			}
		}

		try
		{
			if ($ids === [])
			{
				return $busyIds;
			}

			if ($guids !== [] && Loader::includeModule('ui'))
			{
				try
				{
					// The rows go whatever this answers: see the note of drop().
					(new TempFileBatchRemover())->removeByGuids($guids);
				}
				catch (\Throwable $exception)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				}
			}

			try
			{
				FileUploadSessionTable::deleteByFilter(['@ID' => $ids]);
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);

				return array_merge($busyIds, $ids);
			}

			return $busyIds;
		}
		finally
		{
			foreach ($heldLocks as $heldLock)
			{
				$connection->unlock($heldLock);
			}
		}
	}

	private function dropAcceptedBytes(EO_FileUploadSession $session): void
	{
		$guid = $this->findTempFileGuid($session);
		if ($guid === '' || !Loader::includeModule('ui'))
		{
			return;
		}

		$tempFile = TempFileTable::query()
			->setSelect(['*'])
			->where('GUID', $guid)
			->setLimit(1)
			->fetchObject()
		;

		// Deleting the row purges the temp content and the b_file behind it: TempFileTable::onDelete().
		$tempFile?->delete();
	}

	/**
	 * A token of the ui circuit is "<guid>.<signature>": the signature guards a token that came from
	 * outside, and this one the door has stored itself.
	 */
	private function findTempFileGuid(EO_FileUploadSession $session): string
	{
		$token = (string)$session->getUploadToken();

		return $token === '' ? '' : explode('.', $token, 2)[0];
	}
}
