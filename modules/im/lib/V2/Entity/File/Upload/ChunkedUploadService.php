<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Disk\TypeFile;
use Bitrix\Im\Model\EO_FileUploadSession;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Chunk;
use Bitrix\UI\FileUploader\Uploader;
use Bitrix\UI\FileUploader\UploaderError;

Loader::requireModule('ui');

/**
 * The chunked upload door of im: open a session, take a chunk, tell where the upload stands, finish it one
 * of the two ways or throw it away.
 *
 * The bytes themselves belong to the ui circuit; this service owns everything around them - the session the
 * caller holds in its hand, the order the chunks have to arrive in, and the moment the file stops being a
 * temporary one and becomes a message in a chat ({@see self::finish()}) or a file of the folder of that
 * chat that nothing has published ({@see self::finishToFolder()}). Not one code of the circuit leaves
 * through here: every refusal of it goes through UploadErrorMapper, and the refusals of the door itself are
 * codes of FileError and never go near the mapper, which would collapse them into the general refusal of an
 * upload.
 *
 * 🔴 ONE NAMED LOCK HOLDS append, BOTH HALVES OF THE FINISH AND cancel, and it has to hold them all.
 * Idempotency by the identifiers the session keeps saves a repeat, not a race: two finishes arriving
 * together would both find the session unfinished, both commit the bytes and leave two files of Disk. And
 * they all decide by the row read UNDER that lock, never by the object the controller bound before it: a
 * check made outside the lock and an act made inside it is not one act at all
 * ({@see self::underSessionLock()}).
 *
 * status writes nothing and never waits: a reader queuing behind a writer would turn the progress bar of
 * a client into a queue. It touches the lock in one branch only - the one where the circuit has already
 * disowned the token - and only to tell a finish in the middle of publishing from an upload that is
 * really broken ({@see self::answerForAnUploadTheCircuitLostHoldOf()}).
 *
 * start has a lock of its own, by the caller and not by the session: the ceiling of open sessions is
 * counted and the row is written under it, or the ceiling bounds nothing ({@see self::underStartLock()}).
 */
final class ChunkedUploadService
{
	/**
	 * The lock the starts of one caller stand in line for, and the only one of this door that WAITS.
	 *
	 * The other lock guards a session, and two requests inside one session are a client doing something
	 * wrong: it is told so at once. This one guards a caller, and two starts of one caller at the same
	 * moment are a person dropping three files into a chat - perfectly correct, and refusing two of them
	 * would be the door inventing an error. A start is a count and an insert, so the wait is measured in
	 * milliseconds and a queue of twenty of them still fits inside this second.
	 */
	private const START_LOCK_TIMEOUT = 1;

	/** Width of FILE_NAME and MIME_TYPE in b_im_file_upload_session. */
	private const MAX_FIELD_LENGTH = 255;

	/**
	 * The shape of the signature half of a token of the circuit: hexadecimal from end to end.
	 *
	 * The D modifier is not decoration. Without it $ also matches in front of a trailing newline, and a
	 * signature ending in one would pass for hexadecimal and reach the kernel, which is the one thing
	 * {@see self::findDamagedTokenRefusal()} exists to prevent.
	 */
	private const HEX_SIGNATURE_PATTERN = '/^[0-9a-f]+$/iD';

	private UploadSessionService $sessions;
	private FileService $fileService;
	private UploadErrorMapper $errorMapper;

	/** @var \Closure(string, int): ChunkWindow */
	private \Closure $windowFactory;

	/**
	 * @param \Closure(string, int): ChunkWindow|null $windowFactory A seam, and the only way to stage an
	 *        empty window at all: both of its bounds come from the machine the code runs on - the php.ini of
	 *        the portal and a bucket of a cloud storage - and neither can be moved from inside a test.
	 */
	public function __construct(
		?UploadSessionService $sessions = null,
		?FileService $fileService = null,
		?UploadErrorMapper $errorMapper = null,
		?\Closure $windowFactory = null,
	)
	{
		$this->sessions = $sessions ?? new UploadSessionService();
		$this->fileService = $fileService ?? new FileService();
		$this->errorMapper = $errorMapper ?? new UploadErrorMapper();
		$this->windowFactory = $windowFactory
			?? static fn (string $fileName, int $fileSize): ChunkWindow => ChunkWindow::forFile($fileName, $fileSize)
		;
	}

	/**
	 * Opens a session of the door and answers the window a chunk of it has to fit into.
	 *
	 * Everything that can be refused before a byte travels is refused here, in the order the cost of the
	 * answer grows: the shape of the fields, the window of the portal, how many sessions the caller
	 * already holds, then the limit of the portal and the name of the file. The last three happen under
	 * the lock of the caller, because the count of the sessions and the insert of one have to be one act.
	 *
	 * The mime type is normalized exactly where Folder::uploadFile normalizes it - before anything is
	 * written down - because the circuit builds its temporary file out of what the session keeps.
	 *
	 * @return Result data: ['uploadId', 'chunkSize', 'minChunkSize', 'maxChunkSize', 'expiresAt'].
	 */
	public function start(
		Chat $chat,
		int $userId,
		string $fileName,
		int $fileSize,
		string $mimeType,
	): Result
	{
		$result = new Result();

		$normalizedMimeType = TypeFile::normalizeMimeType($mimeType, $fileName);

		// 🔴 AN EMPTY NAME IS REFUSED HERE AND NOT BY THE ROW. FILE_NAME is a required field of the table,
		// and the insert of a nameless session fails - but save() of the orm answers with a Result and
		// throws nothing, so the door used to answer 200 with a handle no row stood behind and then refuse
		// every call made under it with CHAT_NOT_FOUND. The code is the one im.v2.File.upload answers with
		// for the very same file, and the phrase is its literal English for the same reason.
		if ($fileName === '')
		{
			return $result->addError(new Error('File name is required', 'FILE_EMPTY'));
		}

		// The row of the session holds both in a column of 255, and a field over that is a caller mistake
		// and not a failure of the kernel: without this the insert would either throw under a strict
		// sql_mode or quietly keep a name that is not the name of the file.
		$tooLong = $this->findFieldTooLongRefusal($fileName, $normalizedMimeType);
		if ($tooLong !== null)
		{
			return $result->addError($tooLong);
		}

		$window = ($this->windowFactory)($fileName, $fileSize);
		if ($window->isEmpty())
		{
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_WINDOW_EMPTY, $window->toRest()));
		}

		// 🔴 THE COUNT AND THE INSERT ARE ONE ACT, AND THE LOCK IS WHAT MAKES THEM ONE. Counted outside a
		// lock, the ceiling bounds nothing: start carries no file and is cheap to repeat, so a dozen of
		// them fired together all read a number below the limit - not one of them had written its row yet -
		// and the caller came out holding more sessions than the limit allows.
		return $this->underStartLock(
			$userId,
			fn (): Result => $this->openUnderLock($chat, $userId, $fileName, $fileSize, $normalizedMimeType, $window),
		);
	}

	/**
	 * The part of a start no other start of the same caller may be inside of: what the caller already
	 * holds is counted, and the row that changes that number is written, with nobody in between.
	 *
	 * @param ChunkWindow $window Already built by start, and built once: both of its bounds are read off
	 *        the machine and neither can change between the two sides of the lock.
	 */
	private function openUnderLock(
		Chat $chat,
		int $userId,
		string $fileName,
		int $fileSize,
		string $normalizedMimeType,
		ChunkWindow $window,
	): Result
	{
		$result = new Result();

		// 🔴 NOTHING ELSE BOUNDS HOW MANY SESSIONS ONE CALLER OPENS. start carries no file with it, so it
		// is cheap to repeat, and every session it leaves behind holds a row and up to the whole ceiling
		// of a file in temporary bytes until it runs out six hours later.
		if ($this->sessions->countOpen($userId) >= UploadSessionService::OPEN_SESSION_LIMIT)
		{
			return $result->addError(new FileError(
				FileError::UPLOAD_TOO_MANY_SESSIONS,
				['limit' => UploadSessionService::OPEN_SESSION_LIMIT],
			));
		}

		$refusal = $this->fileService->findUploadRefusal($chat, $fileName, $fileSize);
		if ($refusal !== null)
		{
			return $result->addError($refusal);
		}

		$session = $this->sessions->open(
			(int)$chat->getId(),
			$userId,
			$fileName,
			$fileSize,
			$normalizedMimeType,
		);

		if ($session === null)
		{
			// A handle that no row stands behind is worse than a refusal: every call made under it would
			// answer as if the session belonged to somebody else.
			return $result->addError(new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE));
		}

		return $result->setData(array_merge(
			['uploadId' => (string)$session->getGuid()],
			$window->toRest(),
			['expiresAt' => $session->getExpiresAt()],
		));
	}

	/**
	 * Takes one chunk of the file, under the lock of the session.
	 *
	 * @return Result data: ['receivedSize', 'size', 'done'].
	 */
	public function append(EO_FileUploadSession $session, Chat $chat, Chunk $chunk): Result
	{
		return $this->underSessionLock(
			$session,
			fn (EO_FileUploadSession $current): Result => $this->appendUnderLock($current, $chat, $chunk),
		);
	}

	/**
	 * Tells where the upload stands. Nothing here writes, and nothing here waits for a writer.
	 *
	 * A finished session answers by what it wrote down, whichever of the two ways it finished
	 * ({@see UploadSessionService::isFinished()}). The circuit cannot answer for it any more - the row of
	 * its temporary file went away with the commit - and asking it would turn a finished upload into an
	 * unknown token.
	 *
	 * @param Chat|null $chat The chat of the session. Nothing of a status is decided by it; it is handed
	 *        down so that every controller the door builds carries the chat it was called with
	 *        ({@see ChatUploaderController::isAvailable()}).
	 *
	 * @return Result data: ['receivedSize', 'size', 'done'].
	 */
	public function status(EO_FileUploadSession $session, ?Chat $chat = null): Result
	{
		$result = new Result();

		if ($this->sessions->isFinished($session))
		{
			return $result->setData($this->buildProgress($session, (int)$session->getFileSize(), true));
		}

		$token = (string)$session->getUploadToken();
		if ($token === '')
		{
			return $result->setData($this->buildProgress($session, 0, false));
		}

		$damagedToken = $this->findDamagedTokenRefusal($token);
		if ($damagedToken !== null)
		{
			return $result->addError($damagedToken);
		}

		$statusResult = $this->createUploader($session, $chat)->getStatus($token);
		if ($statusResult->isSuccess())
		{
			return $result->setData(
				$this->buildProgress($session, $statusResult->getReceivedSize(), $statusResult->isDone()),
			);
		}

		return $this->answerForAnUploadTheCircuitLostHoldOf($session, $statusResult->getErrors());
	}

	/**
	 * 🔴 AN UPLOAD BEING FINISHED LOOKS EXACTLY LIKE A BROKEN ONE FROM OUTSIDE, AND THE TWO MUST NOT BE
	 * ANSWERED ALIKE. Either half of the finish lets the circuit go of the bytes before it has anything to
	 * write into the row: makePersistent() removes the temporary file, and the identifiers appear only after
	 * the whole registration. A status falling into that window asks the circuit about a token it no longer
	 * knows and reads a session that still says nothing was finished - and used to answer
	 * FILE_UPLOAD_FAILED, a refusal a caller gives up on, about an upload that was about to come out whole.
	 *
	 * 🔴 AND THIS IS THE ONE PLACE A status TOUCHES THE LOCK, on the path that was already going to be a
	 * refusal. The lock is the only thing that can tell a writer in the middle of the window from a session
	 * nobody is holding at all; the row alone cannot, because inside the window the row says nothing yet.
	 * Taken with no timeout, so a progress bar never queues up behind a writer - which is the whole reason
	 * status stays outside the lock everywhere else - and held for exactly one read of the row. The price
	 * is that a status landing in this branch while the lock is free briefly holds it, and a finish or an
	 * append arriving in that instant is told the session is busy; they get there only when the circuit has
	 * already lost the bytes, where there was nothing left to lose anyway.
	 *
	 * @param Error[] $circuitErrors what the circuit answered about the token
	 */
	private function answerForAnUploadTheCircuitLostHoldOf(
		EO_FileUploadSession $session,
		array $circuitErrors,
	): Result
	{
		$result = new Result();
		$lockName = UploadSessionService::lockOfSession((string)$session->getGuid());
		$connection = Application::getConnection();

		if (!$connection->lock($lockName, 0))
		{
			// Somebody is writing, and the only writer that can be here is a half of the finish past
			// makePersistent().
			return $result->addError(new FileError(FileError::UPLOAD_SESSION_BUSY));
		}

		try
		{
			$finished = $this->sessions->findOwn(
				(string)$session->getGuid(),
				(int)$session->getCreatedBy(),
			);

			// Nobody is writing, so the row is final: a message or a file of Disk in it means the finish
			// came off while the caller was holding an older copy of the session.
			if ($finished !== null && $this->sessions->isFinished($finished))
			{
				return $result->setData(
					$this->buildProgress($finished, (int)$finished->getFileSize(), true),
				);
			}
		}
		finally
		{
			$connection->unlock($lockName);
		}

		return $result->addErrors($this->mapCircuitErrors($circuitErrors));
	}

	/**
	 * Throws the session away together with the bytes the circuit holds behind it.
	 *
	 * A session whose file is already published loses only its own row: the message stands, and taking it
	 * back is not what this action promises.
	 *
	 * ⚠️ THE SAME IS TRUE OF A SESSION FINISHED INTO THE FOLDER, AND THERE THE FILE IS NOT PUBLISHED.
	 * The row goes, the file of Disk stays in the folder of the chat, and this service no longer holds a
	 * name for it: the caller keeps the identifier the folder finish answered with, and only the caller
	 * can either publish it or throw it away. Removing it here would take the file from under a caller
	 * that is still free to publish it by that identifier.
	 */
	public function cancel(EO_FileUploadSession $session): Result
	{
		return $this->underSessionLock($session, function (EO_FileUploadSession $current): Result {
			$this->sessions->drop($current);

			return new Result();
		});
	}

	/**
	 * Finishes the upload: the bytes stop belonging to the circuit and become a message in the chat.
	 *
	 * The order is not interchangeable. The file is made persistent first, and only a file that is whole is
	 * made persistent at all; the registration comes after it, because it needs a b_file that nothing is
	 * going to sweep; the session is marked published last, so that a repeat of this call finds the
	 * identifiers it has to answer by. A registration that failed takes the b_file with it - unless it got
	 * as far as creating the file of Disk, and then the b_file belongs to that file and not to this door.
	 *
	 * @return Result data of the published file, the keys im.v2.File.upload answers with.
	 */
	public function finish(EO_FileUploadSession $session, Chat $chat, ?string $messageText = null): Result
	{
		return $this->underSessionLock(
			$session,
			fn (EO_FileUploadSession $current): Result => $this->finishUnderLock($current, $chat, $messageText),
		);
	}

	/**
	 * Finishes the upload the other way: the bytes stop belonging to the circuit and become a file of the
	 * folder of the chat, with nothing published. The caller holds its identifier and decides for itself
	 * whether a message is ever sent - im.disk.file.commit is the one that publishes it.
	 *
	 * The order and the refusals are those of {@see self::finish()}, and the same lock holds both: two
	 * callers of either half inside one session are still one act. The two differences are that the content
	 * version is left at one - three mechanisms outside this door read that as "not published yet" - and
	 * that the session writes down a file and no message.
	 *
	 * 🔴 AND THE ANSWER CARRIES THE FILE OR IT IS A REFUSAL. A caller of this half takes one thing out of
	 * the answer, and a file registered without a message goes away in the ordinary course of things, so a
	 * success with an empty file would fall over in the hands of the client that came for it
	 * ({@see self::answerByTheFileInTheFolder()}).
	 *
	 * @return Result data: ['file', 'chatId', 'dialogId'], the keys im.v2.File.uploadToFolder answers with.
	 */
	public function finishToFolder(EO_FileUploadSession $session, Chat $chat): Result
	{
		return $this->underSessionLock(
			$session,
			fn (EO_FileUploadSession $current): Result => $this->finishToFolderUnderLock($current, $chat),
		);
	}

	private function appendUnderLock(EO_FileUploadSession $session, Chat $chat, Chunk $chunk): Result
	{
		$result = new Result();
		$uploader = $this->createUploader($session, $chat);
		$token = (string)$session->getUploadToken();

		if ($token === '')
		{
			// Nothing has been accepted yet, so the only chunk that can open the circuit is the one at the
			// beginning of the file. Everything else is named by the offset the door really stands at.
			if (!$chunk->isFirst())
			{
				return $result->addError($this->buildOffsetMismatch(0));
			}

			// 🔴 THE SIGNATURE OF THE CONTENT IS JUDGED HERE AND NOWHERE ELSE, because this chunk carries
			// the head of the file and is the last moment that head is certainly a local file of the
			// portal: behind a cloud bucket the circuit opens its temporary file IN THE BUCKET and every
			// chunk after this one goes straight there. A policy asked later would have to read the head
			// back over the network, and a read that did not come off leaves the write to the NAME alone -
			// a file with an allowed name and forbidden content published by a door that could not look.
			$contentRefusal = $this->fileService->findUploadContentRefusal(
				(string)$session->getFileName(),
				$chunk->getFile()->getPhysicalPath(),
			);
			if ($contentRefusal !== null)
			{
				return $result->addError($contentRefusal);
			}

			$shortChunk = $this->findShortChunkRefusal($session, $chunk);
			if ($shortChunk !== null)
			{
				return $result->addError($shortChunk);
			}

			$uploadResult = $uploader->upload($chunk, null);
			if (!$uploadResult->isSuccess())
			{
				return $result->addErrors($this->mapCircuitErrors($uploadResult->getErrors(), 0));
			}

			if (!$this->sessions->rememberToken($session, (string)$uploadResult->getToken()))
			{
				// The circuit holds the bytes and the door has lost the handle to them: the next chunk would
				// find an empty token, open a SECOND temporary file and leave this one behind. Better to say
				// so now - TempFileAgent of ui sweeps what was left a day later.
				return $result->addError(new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE));
			}

			return $result->setData($this->buildProgress(
				$session,
				$uploadResult->getTempFile()?->getReceivedSize() ?? $chunk->getSize(),
				$uploadResult->isDone(),
			));
		}

		$damagedToken = $this->findDamagedTokenRefusal($token);
		if ($damagedToken !== null)
		{
			return $result->addError($damagedToken);
		}

		// 🔴 THIS STATUS IS A GUARD AND NOT A CONVENIENCE, AND IT MUST STAY IN FRONT OF EVERY CHUNK. It
		// looks like the same SELECT Uploader::upload() makes right after it, and dropping it would save
		// one row read - but a chunk the circuit refuses DESTROYS the upload:
		// Uploader::handleUploadError() deletes the whole temporary file whenever an upload result carries
		// an error, and TempFile::append() refuses a wrong offset with one
		// ({@see \Bitrix\UI\FileUploader\Uploader::handleUploadError()}). A caller that resumed from the
		// wrong byte would lose every byte already accepted - the one thing this door exists to prevent.
		// The other refusal of that method - a chunk running past the declared size - is guarded one layer
		// up, where it costs no row at all ({@see MultipartChunkFactory::fromRequest()}).
		$statusResult = $uploader->getStatus($token);
		if (!$statusResult->isSuccess())
		{
			return $result->addErrors($this->mapCircuitErrors($statusResult->getErrors()));
		}

		$receivedSize = $statusResult->getReceivedSize();

		// 🔴 THE DOOR DECIDES WHAT A REPEAT IS, not the circuit. Uploader::upload() tells a first chunk from
		// the rest by the range of the chunk alone, so a client resending the very first one - the answer to
		// it was lost on the way - would open a SECOND temporary file and throw away every byte accepted so
		// far. Everything already behind the door is answered by the state it is in, and nothing is written
		// twice.
		if ($statusResult->isDone() || $chunk->getEndRange() < $receivedSize)
		{
			return $result->setData($this->buildProgress($session, $receivedSize, $statusResult->isDone()));
		}

		if ($chunk->getStartRange() !== $receivedSize)
		{
			return $result->addError($this->buildOffsetMismatch($receivedSize));
		}

		$shortChunk = $this->findShortChunkRefusal($session, $chunk);
		if ($shortChunk !== null)
		{
			return $result->addError($shortChunk);
		}

		$uploadResult = $uploader->upload($chunk, $token);
		if (!$uploadResult->isSuccess())
		{
			return $result->addErrors($this->mapCircuitErrors($uploadResult->getErrors(), $receivedSize));
		}

		return $result->setData($this->buildProgress(
			$session,
			$uploadResult->getTempFile()?->getReceivedSize() ?? $receivedSize + $chunk->getSize(),
			$uploadResult->isDone(),
		));
	}

	private function finishUnderLock(EO_FileUploadSession $session, Chat $chat, ?string $messageText): Result
	{
		$result = new Result();

		$publishedAnswer = $this->buildPublishedAnswer($session, $chat);
		if ($publishedAnswer !== null)
		{
			return $result->setData($publishedAnswer);
		}

		$token = (string)$session->getUploadToken();
		if ($token === '')
		{
			return $result->addError($this->buildNotComplete($session, 0));
		}

		$damagedToken = $this->findDamagedTokenRefusal($token);
		if ($damagedToken !== null)
		{
			return $result->addError($damagedToken);
		}

		$uploader = $this->createUploader($session, $chat);
		$statusResult = $uploader->getStatus($token);
		if (!$statusResult->isSuccess())
		{
			return $result->addErrors($this->mapCircuitErrors($statusResult->getErrors()));
		}

		if (!$statusResult->isDone())
		{
			return $result->addError($this->buildNotComplete($session, $statusResult->getReceivedSize()));
		}

		$pendingFiles = $uploader->getPendingFiles([$token]);
		$pendingFile = $pendingFiles->get($token);
		$bFileId = (int)$pendingFile?->getFileId();

		if ($pendingFile === null || !$pendingFile->isValid() || $bFileId <= 0)
		{
			// A pending file that is not valid and names no reason would otherwise leave an empty success:
			// the circuit always gives a reason, the fallback is here so that silence cannot become one.
			$refusals = $this->mapCircuitErrors($pendingFile?->getErrors() ?? []);

			return $result->addErrors(
				$refusals === [] ? [new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE)] : $refusals,
			);
		}

		$pendingFiles->makePersistent();

		$registration = $this->fileService->attachUploadedFileToChat(
			$chat,
			$bFileId,
			(string)$session->getFileName(),
			(int)$session->getCreatedBy(),
			$messageText,
		);

		if (!$registration->isSuccess())
		{
			// Nothing owns the content any more once the circuit has let go of it, so the door takes it
			// back - except where the registration already handed it to a file of Disk.
			if ((int)($registration->getData()['diskFileId'] ?? 0) === 0)
			{
				\CFile::delete($bFileId);
			}

			return $result->addErrors($registration->getErrors());
		}

		$data = $registration->getData();

		// 🔴 A ROW THAT COULD NOT BE WRITTEN DOWN HERE DOES NOT TURN THE ANSWER INTO A REFUSAL. The message
		// is published and the file is in the chat; telling the caller it failed would make him repeat the
		// finish, and a repeat that finds nothing written down publishes a SECOND message. What is lost is
		// the idempotency of this one session, and that loss is in the log of the portal
		// ({@see UploadSessionService::markPublished()}) - which is the only place it can be noticed.
		$this->sessions->markPublished(
			$session,
			(int)($data['file']['id'] ?? 0),
			(int)($data['messageId'] ?? 0),
		);

		return $result->setData($data);
	}

	private function finishToFolderUnderLock(EO_FileUploadSession $session, Chat $chat): Result
	{
		$result = new Result();

		// 🔴 THE ONE CROSS-MODE GUARD OF THE DOOR, AND IT IS A REFUSAL AND NOT AN ANSWER. A session whose
		// file is already a message must not be answered by the identifier of that file: the caller of this
		// half hands what it gets to im.disk.file.commit, which would publish the very same file a second
		// time. The reverse order needs no guard of its own - a finish after this one finds a session the
		// circuit has no bytes for and refuses by itself.
		if ((int)$session->getMessageId() > 0)
		{
			return $result->addError(new FileError(FileError::UPLOAD_ALREADY_PUBLISHED));
		}

		if ((int)$session->getDiskFileId() > 0)
		{
			return $this->answerByTheFileInTheFolder($session, $chat);
		}

		$token = (string)$session->getUploadToken();
		if ($token === '')
		{
			return $result->addError($this->buildNotComplete($session, 0));
		}

		$damagedToken = $this->findDamagedTokenRefusal($token);
		if ($damagedToken !== null)
		{
			return $result->addError($damagedToken);
		}

		$uploader = $this->createUploader($session, $chat);
		$statusResult = $uploader->getStatus($token);
		if (!$statusResult->isSuccess())
		{
			return $result->addErrors($this->mapCircuitErrors($statusResult->getErrors()));
		}

		if (!$statusResult->isDone())
		{
			return $result->addError($this->buildNotComplete($session, $statusResult->getReceivedSize()));
		}

		$pendingFiles = $uploader->getPendingFiles([$token]);
		$pendingFile = $pendingFiles->get($token);
		$bFileId = (int)$pendingFile?->getFileId();

		if ($pendingFile === null || !$pendingFile->isValid() || $bFileId <= 0)
		{
			// A pending file that is not valid and names no reason would otherwise leave an empty success:
			// the circuit always gives a reason, the fallback is here so that silence cannot become one.
			$refusals = $this->mapCircuitErrors($pendingFile?->getErrors() ?? []);

			return $result->addErrors(
				$refusals === [] ? [new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE)] : $refusals,
			);
		}

		$pendingFiles->makePersistent();

		$registration = $this->fileService->attachUploadedFileToChatFolder(
			$chat,
			$bFileId,
			(string)$session->getFileName(),
			(int)$session->getCreatedBy(),
			false,
		);

		// 🔴 THE IDENTIFIER OF THE FILE IS READ AND JUDGED, NOT DEFAULTED TO ZERO. markRegistered() writes
		// whatever it is handed, and a zero written into DISK_FILE_ID would make a row that the two readers
		// of the predicate disagree about: isFinished() reads it as unfinished - so a repeat would register
		// a SECOND file - while the count of open sessions, which asks the same question as an IS NULL
		// filter, has already dropped it. Today's flows cannot write such a row, and this is what keeps it
		// so: a registration that came back without an identifier is treated as the failure it is.
		$diskFileId = (int)($registration->getData()['diskFileId'] ?? 0);

		if (!$registration->isSuccess() || $diskFileId <= 0)
		{
			// Nothing owns the content any more once the circuit has let go of it, so the door takes it
			// back - and here it takes it back unconditionally. The registering half of FileService answers
			// a refusal with no data at all and a success with the identifier of the file it made, so there
			// is no branch of it where a file of Disk holds this b_file and the door does not know the id.
			\CFile::delete($bFileId);

			$refusals = $registration->getErrors();

			return $result->addErrors(
				$refusals === [] ? [new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE)] : $refusals,
			);
		}

		// 🔴 A ROW THAT COULD NOT BE WRITTEN DOWN HERE DOES NOT TURN THE ANSWER INTO A REFUSAL. The file is
		// in the folder of the chat; telling the caller it failed would make him repeat the call, and a
		// repeat that finds nothing written down registers a SECOND file. What is lost is the idempotency of
		// this one session, and that loss is in the log of the portal
		// ({@see UploadSessionService::markRegistered()}) - which is the only place it can be noticed.
		$this->sessions->markRegistered($session, $diskFileId);

		// The answer is built by the same builder off the same row the repeat will be answered by, and not
		// out of what the registration returned: that one carries the identifier alone, and the entity of
		// the file is not in it.
		return $this->answerByTheFileInTheFolder($session, $chat);
	}

	/**
	 * 🔴 THE ANSWER OF THE FOLDER HALF CARRIES THE FILE OR IT IS A REFUSAL. The builder answers null when
	 * the file of Disk the row names is not there any more, and on a repeat that is the ordinary course of
	 * things - the caller discarded the draft, or the cleanup of im got to it. A success carrying an empty
	 * file would put nothing where the one field the client came for belongs, so the absence is named.
	 *
	 * On the first call the same branch is unreachable: the file was registered one statement earlier. It
	 * is shared by both so that the invariant has no hole to leak through.
	 */
	private function answerByTheFileInTheFolder(EO_FileUploadSession $session, Chat $chat): Result
	{
		$result = new Result();
		$answer = $this->buildRegisteredAnswer($session, $chat);

		return $answer === null
			? $result->addError(new FileError(FileError::UPLOAD_FILE_GONE))
			: $result->setData($answer)
		;
	}

	/**
	 * The answer of a session whose file lies in the folder of the chat, rebuilt out of what it wrote down,
	 * or null when that file is gone from Disk. The mirror of {@see self::buildPublishedAnswer()} without
	 * the message: there is none, and the caller is the one who decides whether there ever will be.
	 */
	private function buildRegisteredAnswer(EO_FileUploadSession $session, Chat $chat): ?array
	{
		$chatId = (int)$chat->getId();
		$file = FileItem::initByDiskFileId((int)$session->getDiskFileId(), $chatId)?->toRestFormat();

		if ($file === null)
		{
			return null;
		}

		return [
			'file' => $file,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		];
	}

	/**
	 * The answer of a session whose file is already published, rebuilt out of what it wrote down, or null
	 * when there is nothing published yet. A second finish answers by this and publishes no second message.
	 *
	 * The published message is what decides, and not the file beside it: one upload is one message, and a
	 * session that managed to write down a message and nothing else must still not publish a second one.
	 */
	private function buildPublishedAnswer(EO_FileUploadSession $session, Chat $chat): ?array
	{
		$messageId = (int)$session->getMessageId();
		if ($messageId <= 0)
		{
			return null;
		}

		$diskFileId = (int)$session->getDiskFileId();
		$chatId = (int)$chat->getId();

		return [
			'file' => FileItem::initByDiskFileId($diskFileId, $chatId)?->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		];
	}

	/**
	 * 🔴 THE SESSION IS READ AGAIN UNDER THE LOCK, AND THE WORK IS DONE BY THAT ROW AND NOT BY THE OBJECT
	 * THE CALLER ARRIVED WITH. The controller builds the session while binding the arguments of the action,
	 * which is before any lock is taken: two requests that started together both hold a copy of the row as
	 * it was BEFORE either of them wrote into it. Holding the lock and then deciding by that copy is the
	 * check standing outside the lock and the act inside it - two first chunks would both find an empty
	 * token, both open a temporary file of the circuit, and the second would overwrite the token of the
	 * first, leaving its bytes unreachable through the uploadId until the cleanup swept them.
	 *
	 * A row that is gone by the time the lock is taken was cancelled by a request that got here first.
	 * There is nothing left to append to, to finish or to throw away, and the caller is told the one thing
	 * it can act on: this handle is no good any more, open a new session.
	 *
	 * @param \Closure(EO_FileUploadSession): Result $work
	 */
	private function underSessionLock(EO_FileUploadSession $session, \Closure $work): Result
	{
		$lockName = UploadSessionService::lockOfSession((string)$session->getGuid());
		$connection = Application::getConnection();

		// lock() answers true when the lock was taken and false when somebody else is holding it.
		if (!$connection->lock($lockName, 0))
		{
			return (new Result())->addError(new FileError(FileError::UPLOAD_SESSION_BUSY));
		}

		try
		{
			$current = $this->sessions->findOwn(
				(string)$session->getGuid(),
				(int)$session->getCreatedBy(),
			);

			if ($current === null)
			{
				return (new Result())->addError(new FileError(FileError::UPLOAD_SESSION_EXPIRED));
			}

			return $work($current);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * The lock the starts of one caller stand in line for. Unlike the lock of a session this one waits:
	 * see {@see self::START_LOCK_TIMEOUT}.
	 *
	 * @param \Closure(): Result $work
	 */
	private function underStartLock(int $userId, \Closure $work): Result
	{
		$lockName = UploadSessionService::lockOfStartsOf($userId);
		$connection = Application::getConnection();

		if (!$connection->lock($lockName, self::START_LOCK_TIMEOUT))
		{
			return (new Result())->addError(new FileError(FileError::UPLOAD_SESSION_BUSY));
		}

		try
		{
			return $work();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function createUploader(EO_FileUploadSession $session, ?Chat $chat = null): Uploader
	{
		return new Uploader(new ChatUploaderController(
			[
				'chatId' => (int)$session->getChatId(),
				'userId' => (int)$session->getCreatedBy(),
				'sessionId' => (string)$session->getGuid(),
			],
			// The permission is asked for the owner of the session and for nobody else, whoever handed the
			// chat over and however that chat was bound.
			$chat?->withContextUser((int)$session->getCreatedBy()),
		));
	}

	/**
	 * @return array{receivedSize: int, size: int, done: bool}
	 */
	private function buildProgress(EO_FileUploadSession $session, int $receivedSize, bool $done): array
	{
		// The size is the one the session was opened with: a caller cannot move the finish line by what it
		// sends along with a chunk.
		return [
			'receivedSize' => $receivedSize,
			'size' => (int)$session->getFileSize(),
			'done' => $done,
		];
	}

	/**
	 * 🔴 A TOKEN OF ANOTHER SHAPE IS A ROW THAT GOT DAMAGED, AND IT MUST NOT LEAVE AS AN EXCEPTION. The
	 * circuit checks the signature of a token through {@see \Bitrix\Main\Security\Sign\Signer::validate()},
	 * which THROWS BadSignatureException on a signature that is not hexadecimal instead of answering false.
	 * No caller can reach that - the token is written down by this door and never travels outside - so the
	 * only way to meet it is a damaged row, and a REST action that threw would answer a failure of the
	 * kernel where the door promises a refusal of its own.
	 *
	 * 🔴 AND THE SHAPE IS READ BY pcre, NEVER BY ctype. ctype is an extension of its own and a portal may
	 * be built without it; there a call to ctype_xdigit() is a fatal error, and this method stands in
	 * front of append, status and finish - so the door would die on the second chunk of every upload,
	 * which is the whole of what it exists for. pcre is compiled into php itself and asks for nothing.
	 */
	private function findDamagedTokenRefusal(string $token): ?Error
	{
		$parts = explode('.', $token, 2);
		$isWellFormed =
			count($parts) === 2
			&& $parts[0] !== ''
			&& $parts[1] !== ''
			&& preg_match(self::HEX_SIGNATURE_PATTERN, $parts[1]) === 1
		;

		return $isWellFormed ? null : new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE);
	}

	/**
	 * The two fields of the session a caller writes himself, held to the width of the column that keeps
	 * them. The name is the one the file will carry; the type is what the circuit builds its temporary
	 * file with.
	 */
	private function findFieldTooLongRefusal(string $fileName, string $mimeType): ?FileError
	{
		$fields = ['name' => $fileName, 'type' => $mimeType];

		foreach ($fields as $field => $value)
		{
			if (mb_strlen($value) > self::MAX_FIELD_LENGTH)
			{
				return new FileError(
					FileError::UPLOAD_FIELD_TOO_LONG,
					['field' => $field, 'maxLength' => self::MAX_FIELD_LENGTH],
				);
			}
		}

		return null;
	}

	/**
	 * 🔴 A CHUNK UNDER THE LOWER BOUND OF THE WINDOW DESTROYS THE WHOLE UPLOAD BEHIND A CLOUD BUCKET, and
	 * nothing below this point refuses it in time. {@see \Bitrix\UI\FileUploader\TempFile::appendToCloud()}
	 * raises CLOUD_INVALID_CHUNK_SIZE for a part smaller than getMinUploadPartSize() that is not the last
	 * one, and Uploader::handleUploadError() answers any refusal of an append by deleting the temporary
	 * file with every byte accepted so far - while the row of the session goes on naming the token of a
	 * file that is gone, so neither status nor append nor finish can carry that upload on. The caller
	 * would have to start the whole thing over, and the only thing it did wrong was send a short part.
	 *
	 * The condition is the one of the circuit word for word, down to the exception for the last chunk, so
	 * that the two cannot drift apart. The chunk knows which one it is out of the size of the session, not
	 * out of the request ({@see MultipartChunkFactory::fromRequest()}).
	 *
	 * 🔴 AND THE LOWER BOUND IS ASKED HERE, ON EVERY CHUNK BUT THE LAST, however much it costs. It is the
	 * number a chunk is really judged by, and a bound nobody asks for bounds nothing: the door would be
	 * naming minChunkSize at start and then letting the circuit tear the upload down over it. On a portal
	 * without a cloud the question is nearly free - {@see ChunkWindow::findCloudPartSize()} is cut short by
	 * the missing module - and on a cloud portal it is the one machine where the answer decides anything.
	 */
	private function findShortChunkRefusal(EO_FileUploadSession $session, Chunk $chunk): ?FileError
	{
		if ($chunk->isLast())
		{
			return null;
		}

		$window = ($this->windowFactory)((string)$session->getFileName(), (int)$session->getFileSize());
		if ($chunk->getSize() >= $window->getMin())
		{
			return null;
		}

		return new FileError(FileError::UPLOAD_CHUNK_TOO_SMALL, $window->toRest());
	}

	private function buildOffsetMismatch(int $receivedSize): FileError
	{
		return new FileError(FileError::UPLOAD_CHUNK_OFFSET_MISMATCH, ['receivedSize' => $receivedSize]);
	}

	private function buildNotComplete(EO_FileUploadSession $session, int $receivedSize): FileError
	{
		return new FileError(FileError::UPLOAD_NOT_COMPLETE, [
			'receivedSize' => $receivedSize,
			'size' => (int)$session->getFileSize(),
		]);
	}

	/**
	 * @param Error[] $errors refusals of the ui circuit, and of nothing else
	 *
	 * @return Error[]
	 */
	private function mapCircuitErrors(array $errors, ?int $receivedSize = null): array
	{
		$mapped = [];
		foreach ($errors as $error)
		{
			$mapped[] = $this->errorMapper->map($this->nameTheOffset($error, $receivedSize));
		}

		return $mapped;
	}

	/**
	 * 🔴 THE OFFSET IS PUT INTO THE REFUSAL HERE, because there is nowhere else it could come from. The
	 * circuit raises INVALID_CHUNK_OFFSET with no custom data at all
	 * ({@see \Bitrix\UI\FileUploader\TempFile::append()}), and the mapper carries custom data across but
	 * invents none - so without this the client would be told to resume and not told from where.
	 */
	private function nameTheOffset(Error $error, ?int $receivedSize): Error
	{
		if ($receivedSize === null || (string)$error->getCode() !== UploaderError::INVALID_CHUNK_OFFSET)
		{
			return $error;
		}

		return new UploaderError(
			UploaderError::INVALID_CHUNK_OFFSET,
			array_merge($error->getCustomData(), ['receivedSize' => $receivedSize]),
		);
	}
}
