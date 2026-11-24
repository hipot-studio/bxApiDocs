<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Service;

use Bitrix\Disk;
use Bitrix\Disk\Controller\Response\PreviewResponseBuilder;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\DownloadType;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\File\PhpStreamedFileResponse;
use Bitrix\Im\V2\Entity\File\PreviewSource;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message\Update\Files\FilesValidationService;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class FileService
{
	/**
	 * Maximum decoded file size in bytes (100 MB). Public so that the chunked door keeps the same
	 * ceiling as this one: one number in one place, read by
	 * {@see \Bitrix\Im\V2\Entity\File\Upload\ChatUploaderController::getConfiguration()}.
	 */
	public const MAX_FILE_SIZE = 100 * 1024 * 1024;

	/**
	 * Maximum decoded size of a whole batch, in bytes (16 MB). Its own number, not a share of MAX_FILE_SIZE:
	 * a batch of that size has to survive being decoded, and memory is what decides that, not the contract.
	 *
	 * Measured on a live request: the body of a POST costs 2.0 x the length of the base64 it carries (the
	 * raw body and $_POST hold it at once), decoding adds 1.5 x the decoded size S (the encoded string and
	 * its result are held together), and the prolog with the modules of the portal takes 11.3 MiB before a
	 * byte of payload arrives. The base64 of S bytes is 4/3 x S, so
	 *
	 *     M(S) = 11.3 MiB + (2.0 x 4/3 + 1.5) x S = 11.3 MiB + 4.17 x S
	 *
	 * At memory_limit = 128M that puts the ceiling near S = 28 MiB, and the 100 MB the single-file contract
	 * promises would need about 441 MiB - unreachable threefold. 16 MiB costs about 78 MiB of the 128 and
	 * leaves about 50 MiB for everything the real /rest/ door carries beyond the bare script the figure was
	 * measured on: the REST layer, the prefilters, the autowiring of the chat, Disk. A limit closer to the
	 * ceiling would spend that margin on a portal with heavy event handlers.
	 *
	 * Clients lose nothing to the number: at the usual post_max_size = 8M a batch runs out of body at about
	 * 5.6 MB of files, long before this limit is reached.
	 */
	private const MAX_BATCH_TOTAL_SIZE = 16 * 1024 * 1024;

	/**
	 * Leading bytes of an upload handed to the format policy ({@see self::findUploadContentRefusal()}).
	 *
	 * Deliberately more than the signature detector of disk asks for (512 at the time of writing): disk
	 * cuts the head down to its own length itself, so a number above it costs nothing and keeps this door
	 * from running short if that length ever grows.
	 */
	private const CONTENT_HEAD_LENGTH = 8192;

	/** Longest file name of a request a refusal quotes back, in characters. Disk keeps names far shorter. */
	private const QUOTED_FILE_NAME_MAX_LENGTH = 255;

	/** Repeats the cache time \Bitrix\Disk\Controller\Response\PreviewResponseBuilder sets for a resized image. */
	private const PREVIEW_CACHE_TIME = 86400;

	/**
	 * Y makes a cross-origin direct download send its body by PHP. Meant for a portal whose web server
	 * drops Access-Control-Allow-Origin once it takes the body over; any other value, or none, keeps the
	 * fast download of the portal.
	 */
	private const CORS_VIA_PHP_OPTION = 'direct_download_cors_via_php';

	public function uploadFileToChat(
		Chat $chat,
		string $fileName,
		string $base64Content,
		int $actorUserId,
		?string $messageText = null,
	): Result
	{
		$uploadResult = $this->uploadFileToChatFolder($chat, $fileName, $base64Content, $actorUserId);
		if (!$uploadResult->isSuccess())
		{
			return $uploadResult;
		}

		$result = new Result();
		$chatId = $chat->getId();
		$fileId = (int)$uploadResult->getData()['fileId'];
		$messageFields = [
			'CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorUserId,
			'SKIP_USER_CHECK' => 'Y',
			'PARAMS' => ['FILE_ID' => [$fileId]],
		];

		if (!empty($messageText))
		{
			$messageFields['MESSAGE'] = $messageText;
		}

		$messageId = \CIMMessenger::Add($messageFields);

		if (!$messageId)
		{
			return $result->addError(new Error('Failed to send file message', 'FILE_SEND_FAILED'));
		}

		$fileItem = FileItem::initByDiskFileId($fileId, $chatId);

		$result->setData([
			'file' => $fileItem?->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);

		return $result;
	}

	/**
	 * Registers a file already lying in b_file in the folder of the chat and publishes it as one message.
	 *
	 * Registration is the other half ({@see self::attachUploadedFileToChatFolder()}) and the whole of what
	 * this one adds is the message, the way uploadFileToChat adds it over uploadFileToChatFolder.
	 *
	 * @return Result data of a published file: ['file', 'messageId', 'chatId', 'dialogId'], the keys
	 *                uploadFileToChat answers with. A message that was not published carries 'diskFileId'
	 *                instead: the file of Disk exists by then, and the caller must not take its b_file away.
	 */
	public function attachUploadedFileToChat(
		Chat $chat,
		int $bFileId,
		string $fileName,
		int $actorUserId,
		?string $messageText = null,
	): Result
	{
		$result = $this->attachUploadedFileToChatFolder($chat, $bFileId, $fileName, $actorUserId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$chatId = $chat->getId();
		$diskFileId = (int)$result->getData()['diskFileId'];
		$messageFields = [
			'CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorUserId,
			'SKIP_USER_CHECK' => 'Y',
			'PARAMS' => ['FILE_ID' => [$diskFileId]],
		];

		if (!empty($messageText))
		{
			$messageFields['MESSAGE'] = $messageText;
		}

		$messageId = \CIMMessenger::Add($messageFields);

		if (!$messageId)
		{
			// The file of Disk owns the b_file from here on; the caller is told so, so that undoing the
			// upload does not delete the content out from under a file that exists.
			$result->setData(['diskFileId' => $diskFileId]);

			return $result->addError(new Error('Failed to send file message', 'FILE_SEND_FAILED'));
		}

		$fileItem = FileItem::initByDiskFileId($diskFileId, $chatId);

		return $result->setData([
			'file' => $fileItem?->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);
	}

	/**
	 * Registers a file already lying in b_file in the folder of the chat, publishing nothing.
	 *
	 * The steps are the tail of \Bitrix\Disk\Folder::uploadFile(), the very path the one-shot door of im
	 * walks, repeated here because the bytes came in by another road: the chunked door hands over a b_file
	 * the ui circuit committed, and everything after that commit has to happen exactly as it happens there.
	 *
	 * 🔴 THE LIMIT OF THE PORTAL IS ASKED A SECOND TIME, and that is not a repetition of the check the door
	 * made when the upload was opened: between the two lies the whole upload, up to the six hours a session
	 * of the chunked door lives, and the portal may have run out of space in the meantime.
	 *
	 * 🔴 AND IT IS ASKED FOR NO BYTES, because by this point the bytes are already counted. \CFile::SaveFile
	 * checks the very same quota with the size of the file and then adds that size to main_size.~files
	 * ({@see \CDiskQuota::updateDiskQuota()}), and the ui circuit commits exactly there - on the last chunk,
	 * one call before this one. Asking again with the size would subtract it a second time and demand twice
	 * the free space the one-shot door demands for the same file, refusing an upload the kernel has just
	 * accepted. The question that is still worth asking is the other one: is the portal over its limit now?
	 * That is what a size of zero asks - \CDiskQuota::GetDiskQuota() answers false for a portal over the
	 * limit before any subtraction happens at all.
	 *
	 * The name is corrected the way uploadFile corrects it (Ui\Text::correctFilename): the trim, the emoji
	 * turned into underscores and the repair of a name the field NAME would not take. ObjectNameService
	 * does none of that, so the name of a file of this door would differ from the name of the same file
	 * sent in one shot.
	 *
	 * Both halves of the format policy are made by File::add inside addFile - the name and the head of the
	 * content already stored - so nothing of it is repeated here. The signature half of that one is allowed
	 * to come up short behind a cloud bucket, and nothing of this door rests on it: the signature was
	 * already judged on the local head of the file, at the first chunk
	 * ({@see self::findUploadContentRefusal()}).
	 *
	 * @param bool $registerContentVersion raises the content version of the file, as uploadContentToChatFolder
	 *                                     does by the flag of the same name. 🔴 It belongs to the publishing
	 *                                     half: three mechanisms outside this class read version one as "not
	 *                                     published yet" - im.disk.file.commit raises it itself, the agent
	 *                                     \CIMDisk::RemoveTmpFileAgent sweeps version one and nothing else,
	 *                                     and FileItem reads the status of such a file as 'upload'. A caller
	 *                                     that leaves the file in the folder has to pass false to keep them.
	 *
	 * @return Result data of a registered file: ['diskFileId' => int]. No entity of the file is built here:
	 *                the keys of a published answer belong to the half that publishes.
	 */
	public function attachUploadedFileToChatFolder(
		Chat $chat,
		int $bFileId,
		string $fileName,
		int $actorUserId,
		bool $registerContentVersion = true,
	): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_UPLOAD_FAILED'));
		}

		$fileArray = \CFile::getFileArray($bFileId);
		if (!is_array($fileArray))
		{
			return $result->addError(new Error('File upload failed', 'FILE_UPLOAD_FAILED'));
		}

		$portalQuotaRefusal = $this->findPortalQuotaRefusal(0);
		if ($portalQuotaRefusal !== null)
		{
			return $result->addError($portalQuotaRefusal);
		}

		$folder = $this->getChatFolder($chat);
		if ($folder === null)
		{
			return $result->addError(new Error('Could not get chat folder', 'FILE_FOLDER_ERROR'));
		}

		$fileModel = $folder->addFile(
			[
				'NAME' => Disk\Ui\Text::correctFilename($fileName),
				'FILE_ID' => $bFileId,
				'SIZE' => (int)$fileArray['FILE_SIZE'],
				'CREATED_BY' => $actorUserId,
				Disk\File::FILE_OWNERSHIP_DATA_KEY => Disk\File::FILE_OWNERSHIP_OWNED,
			],
			[],
			true,
		);

		if (!$fileModel)
		{
			return $result->addError(
				$this->findDiskNoSpaceRefusal($folder) ?? new Error('File upload failed', 'FILE_UPLOAD_FAILED'),
			);
		}

		if ($registerContentVersion)
		{
			$fileModel->increaseGlobalContentVersion();
		}

		return $result->setData(['diskFileId' => (int)$fileModel->getId()]);
	}

	public function uploadFileToChatFolder(
		Chat $chat,
		string $fileName,
		string $base64Content,
		int $actorUserId,
		bool $registerContentVersion = true,
	): Result
	{
		if (empty($fileName) || empty($base64Content))
		{
			return (new Result())->addError(new Error('File name and content are required', 'FILE_EMPTY'));
		}

		$maxBase64Length = (int)(self::MAX_FILE_SIZE * 4 / 3);
		if (mb_strlen($base64Content, '8bit') > $maxBase64Length)
		{
			return (new Result())->addError(new Error('File size exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		$decodedContent = base64_decode($base64Content, true);
		if ($decodedContent === false)
		{
			return (new Result())->addError(new Error('Invalid base64 content', 'FILE_INVALID_CONTENT'));
		}

		$uploadResult = $this->uploadContentToChatFolder(
			$chat,
			$fileName,
			$decodedContent,
			$actorUserId,
			$registerContentVersion,
		);
		if ($uploadResult->isSuccess())
		{
			$uploadResult->setData(['fileId' => (int)$uploadResult->getData()['fileId']]);
		}

		return $uploadResult;
	}

	/**
	 * Puts the bytes into the folder of the chat, and names lack of space apart from every other refusal.
	 *
	 * Lack of space reaches this method from two different places and leaves with one code,
	 * FileError::STORAGE_QUOTA_EXCEEDED: the limit of the portal is asked before a byte is written
	 * (findPortalQuotaRefusal), the limits of disk are read out of the folder after it refused the file
	 * (findDiskNoSpaceRefusal). Every other refusal of disk - a full server volume, a cloud storage that
	 * said no, a format that is not allowed - keeps the FILE_UPLOAD_FAILED it always had.
	 */
	public function uploadContentToChatFolder(
		Chat $chat,
		string $fileName,
		string $content,
		int $actorUserId,
		bool $registerContentVersion = true,
	): Result
	{
		$result = new Result();

		if ($fileName === '' || $content === '')
		{
			return $result->addError(new Error('File name and content are required', 'FILE_EMPTY'));
		}

		if (strlen($content) > self::MAX_FILE_SIZE)
		{
			return $result->addError(new Error('File size exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_UPLOAD_FAILED'));
		}

		$portalQuotaRefusal = $this->findPortalQuotaRefusal(strlen($content));
		if ($portalQuotaRefusal !== null)
		{
			return $result->addError($portalQuotaRefusal);
		}

		$folder = $this->getChatFolder($chat);
		if ($folder === null)
		{
			return $result->addError(new Error('Could not get chat folder', 'FILE_FOLDER_ERROR'));
		}

		$fileModel = $folder->uploadFile(
			['name' => $fileName, 'content' => $content],
			['CREATED_BY' => $actorUserId],
			[],
			true,
		);

		if (!$fileModel)
		{
			return $result->addError(
				$this->findDiskNoSpaceRefusal($folder) ?? new Error('File upload failed', 'FILE_UPLOAD_FAILED'),
			);
		}

		if ($registerContentVersion)
		{
			$fileModel->increaseGlobalContentVersion();
		}

		return $result->setData([
			'fileId' => (int)$fileModel->getId(),
			'fileModel' => $fileModel,
		]);
	}

	/**
	 * Uploads a batch of files and publishes all of them in exactly one message.
	 *
	 * All or nothing, and the order of the steps is what makes it so: the batch lands with the content version
	 * left at one, the version is raised only once every file of it was accepted AND every model of it was
	 * read back, and the single message comes last. A refusal at any step publishes nothing and leaves what
	 * landed at version one - the state the existing \CIMDisk::RemoveTmpFileAgent sweeps, so no new kind of
	 * leftover is invented here.
	 *
	 * 🔴 THE PRICE OF THE STEP THAT IS NOT TRANSACTIONAL, named here because it cannot be paid away. Raising
	 * the version is one call per file and nothing rolls it back. A batch refused midway through that loop -
	 * because disk answered false, or because the model of a later file did not read back - leaves the files
	 * already raised standing at version two: unpublished, invisible in the chat, and no longer swept, since
	 * \CIMDisk::RemoveTmpFileAgent only collects version one. They become permanent leftovers.
	 *
	 * This is not a class of leftover the batch invents - the single-file path produces exactly the same one
	 * whenever \CIMMessenger::Add fails after its version was raised (FILE_SEND_FAILED) - but the batch widens
	 * the window from one file to N, and that width is the honest cost of publishing N files as one message.
	 * Lowering a version back is not a repair: viewers cache by that number, so a step backwards would serve
	 * stale content. Deleting what was raised would trade this leftover for a delete that can fail the same
	 * way the raise just did, inside the handling of a failure; that trade has not been made here.
	 *
	 * Every other refusal of the batch - a refused file, a limit, a broken form - happens BEFORE the first
	 * raise and leaves only version-one files, which the sweeper owns.
	 *
	 * @param array<int|string, array{name: string, content: string}> $files keys of the request, in its order
	 *
	 * @return Result data: ['files' => list<array>, 'messageId' => int, 'chatId' => int, 'dialogId' => string].
	 *                 The key 'file' of the single-file answer never appears here.
	 */
	public function uploadFilesToChat(
		Chat $chat,
		array $files,
		int $actorUserId,
		?string $messageText = null,
	): Result
	{
		// stop at the first refusal: any refused file cancels the whole batch anyway, so going on would
		// write files to disk that are certain to be thrown away before the caller ever learns their ids
		$uploadResult = $this->uploadBatchFiles($chat, $files, $actorUserId, false, true);

		$result = new Result();
		$uploadData = $uploadResult->getData();

		/** @var array<int|string, Error> $errors */
		$errors = $uploadData['errors'] ?? [];

		// One refusal format, not two. A file of the batch that was refused cancels the batch and names
		// itself, and it does so whether the rest of the batch landed or nothing of it did - a caller
		// parsing the name out of error_description must not depend on the luck of a neighbouring file.
		if ($errors !== [])
		{
			$refusedKey = array_key_first($errors);
			$refusal = $errors[$refusedKey];
			$refusedName = $this->quoteFileName((string)($files[$refusedKey]['name'] ?? ''));

			return $result->addError(new Error($refusal->getMessage() . ': ' . $refusedName, $refusal->getCode()));
		}

		// a refusal of the batch itself - its shape or one of its limits - names no file of it
		if (!$uploadResult->isSuccess())
		{
			return $uploadResult;
		}

		$chatId = $chat->getId();
		/** @var list<int> $fileIds */
		$fileIds = array_values($uploadData['files'] ?? []);

		// one query for the whole batch, in the order of the request, and the same models are used again
		// for the answer below: raising the version updates them in place, so nothing is read twice
		$fileCollection = FileCollection::initByDiskFilesIds($fileIds, $chatId);

		// 🔴 all or nothing reaches here too. A model that did not load would leave its file at content
		// version 1 under a message that already names it, and the sweeper would take the attachment out
		// of a published message a day later. A batch that cannot raise every version publishes nothing.
		if (count($fileCollection) !== count($fileIds))
		{
			return $result->addError(new Error('Could not read every file of the batch', 'FILE_UPLOAD_FAILED'));
		}

		foreach ($fileCollection as $file)
		{
			$diskFile = $file->getDiskFile();
			if ($diskFile === null)
			{
				return $result->addError(new Error('Could not read every file of the batch', 'FILE_UPLOAD_FAILED'));
			}

			// The answer of disk decides whether the batch may be published at all. A raise that failed and
			// went unnoticed would leave its file at version one under a message that already names it, and
			// the sweeper would take the attachment out of a published message a day later.
			if (!$this->raiseContentVersion($diskFile))
			{
				return $result->addError(
					new Error('Could not register the content version of the batch', 'FILE_UPLOAD_FAILED'),
				);
			}
		}

		$messageFields = [
			'CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorUserId,
			'SKIP_USER_CHECK' => 'Y',
			'PARAMS' => ['FILE_ID' => $fileIds],
		];

		if (!empty($messageText))
		{
			$messageFields['MESSAGE'] = $messageText;
		}

		$messageId = \CIMMessenger::Add($messageFields);

		if (!$messageId)
		{
			return $result->addError(new Error('Failed to send file message', 'FILE_SEND_FAILED'));
		}

		$result->setData([
			// the collection loads the params of the whole batch in one go and its models already carry
			// the version raised above, so the answer costs no read of its own
			'files' => $fileCollection->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);

		return $result;
	}

	/**
	 * Uploads a batch of files into the folder of the chat and publishes nothing.
	 *
	 * Best effort with a threshold: the answer carries what landed under the keys of the request and the cause
	 * of every file that did not. A batch where not a single file was accepted is a refusal instead, because
	 * nothing was reached and a caller reading only the error would take a total loss for a success.
	 *
	 * Every check of a single file stays in uploadFileToChatFolder, which is called once per file; the batch
	 * adds only what a batch of its own can break, and both of its refusals are decided before the first byte
	 * reaches disk.
	 *
	 * Memory is why the loop looks like this: no decoded body is ever put into a variable of this method and no
	 * list of decoded bodies exists at any step, so the peak of a batch is its largest file and not its total.
	 * The encoded string of an entry is handed over as it is and never copied.
	 *
	 * @param array<int|string, array{name: string, content: string}> $files keys of the request are kept
	 *
	 * @return Result data: ['files' => array<int|string, int> key => file id, 'errors' => array<int|string, Error>].
	 *                 A refused batch carries no 'files' at all; when the refusal is the batch reaching
	 *                 nothing, 'errors' is still there, so the caller can name the file that caused it.
	 */
	public function uploadFilesToChatFolder(
		Chat $chat,
		array $files,
		int $actorUserId,
		bool $registerContentVersion = false,
	): Result
	{
		// best effort to the end of the batch: nothing is published, so a caller that gets nine files out of
		// ten has a result to work with and decides for itself whether nine is enough
		return $this->uploadBatchFiles($chat, $files, $actorUserId, $registerContentVersion, false);
	}

	/**
	 * The loop behind both batch methods, and the one place where their two policies of a refusal differ.
	 *
	 * @param array<int|string, array{name: string, content: string}> $files keys of the request are kept
	 * @param bool $stopOnFirstFailure for the door that cancels the whole batch on any refusal. Carrying on
	 *                                 there would write up to nine more files to disk that are already
	 *                                 certain to be thrown away, and leave every one of them to the sweeper.
	 *
	 * @return Result see uploadFilesToChatFolder for the shape of the data
	 */
	protected function uploadBatchFiles(
		Chat $chat,
		array $files,
		int $actorUserId,
		bool $registerContentVersion,
		bool $stopOnFirstFailure,
	): Result
	{
		$batchResult = $this->validateBatch($files);
		if (!$batchResult->isSuccess())
		{
			return $batchResult;
		}

		$uploadedFileIds = [];
		/** @var array<int|string, Error> $errors */
		$errors = [];

		foreach ($files as $key => $file)
		{
			$uploadResult = $this->uploadFileToChatFolder(
				$chat,
				$file['name'],
				$file['content'],
				$actorUserId,
				$registerContentVersion,
			);

			if ($uploadResult->isSuccess())
			{
				$uploadedFileIds[$key] = (int)$uploadResult->getData()['fileId'];

				continue;
			}

			$errors[$key] = $uploadResult->getError() ?? new Error('File upload failed', 'FILE_UPLOAD_FAILED');

			if ($stopOnFirstFailure)
			{
				break;
			}
		}

		$result = new Result();

		if ($uploadedFileIds === [])
		{
			// The batch reached nothing at all, so it speaks with the cause of the file that was refused
			// first. The causes stay in the data as well, under the keys of the request: a caller that has
			// to name the refused file needs them. A list of what landed is deliberately absent - nothing did.
			return $result
				->addError($errors[array_key_first($errors)])
				->setData(['errors' => $errors])
			;
		}

		return $result->setData(['files' => $uploadedFileIds, 'errors' => $errors]);
	}

	/**
	 * Registers a new content version of a file of the batch and answers whether disk accepted it.
	 *
	 * A method of its own for one reason: the refusal of disk is the only way the publication of a batch can
	 * break after every check has passed, and a guard of that path needs a seam to stand on. It adds nothing
	 * to the call.
	 */
	protected function raiseContentVersion(Disk\File $diskFile): bool
	{
		return (bool)$diskFile->increaseGlobalContentVersion();
	}

	/**
	 * Answers the folder of the chat on disk, or null when the chat has none.
	 *
	 * \CIMDisk::GetFolderModel answers false, not null, and that false is normalized here. A method of its
	 * own so that a test can hand the caller a folder carrying a refusal of disk: no quota of disk is
	 * reachable on the path of a chat, so that refusal cannot be provoked for real.
	 */
	protected function getChatFolder(Chat $chat): ?Disk\Folder
	{
		$folder = \CIMDisk::GetFolderModel($chat->getId());

		return $folder instanceof Disk\Folder ? $folder : null;
	}

	/**
	 * Refusals an upload earns by its name and its declared size alone, before a byte of it travels.
	 *
	 * The entry point of the chunked door, which has no content yet and must still answer at the moment the
	 * upload is opened rather than at the first chunk. Exactly two of the questions uploadContentToChatFolder
	 * asks all at once can be asked without bytes, and both are asked here.
	 *
	 * The limit of the portal is the first. The half of the format policy decided by the name is the second:
	 * findFormatPolicyErrorForUpload gets an empty file array on purpose, so readUploadedContentHead answers
	 * null and $options is left WITHOUT the mark of a checked signature. The other half is asked at the
	 * first chunk, where the head of the file really is ({@see self::findUploadContentRefusal()}).
	 *
	 * The personal quota of an employee is not asked, for the reason the working door does not ask it either:
	 * the folder of a chat does not live in a personal storage.
	 *
	 * The refusal of the format policy is answered as the general refusal of an upload, the very code
	 * im.v2.File.upload answers with for the same file: the codes of disk are not a contract of im, and a
	 * localized phrase of another module has no place beside the literal English of this one.
	 *
	 * @param Chat $chat The chat the upload is addressed to. Nothing of it is read yet: both refusals above
	 *                   belong to the portal and to the name, and the folder of the chat is not touched
	 *                   until the file is whole.
	 */
	public function findUploadRefusal(Chat $chat, string $fileName, int $fileSize): ?Error
	{
		if (!Loader::includeModule('disk'))
		{
			return new Error('Disk module not available', 'FILE_UPLOAD_FAILED');
		}

		$portalQuotaRefusal = $this->findPortalQuotaRefusal($fileSize);
		if ($portalQuotaRefusal !== null)
		{
			return $portalQuotaRefusal;
		}

		$options = [];
		$formatPolicyRefusal = Disk\File::findFormatPolicyErrorForUpload([], $fileName, $options);

		return $formatPolicyRefusal === null ? null : new Error('File upload failed', 'FILE_UPLOAD_FAILED');
	}

	/**
	 * The other half of the format policy - the one decided by the signature of the content - asked on the
	 * head of the file while that head is still a local temporary file of the portal.
	 *
	 * 🔴 THIS IS WHERE THE HEAD IS, AND THE ONLY PLACE IT IS CERTAIN TO BE LOCAL. A file sent in one shot
	 * lies whole in $_FILES and Folder::uploadFile judges it there; a file sent in chunks is handed to the
	 * circuit of ui chunk by chunk, and on a portal with a cloud bucket the circuit opens its temporary
	 * file IN THE BUCKET and appends every chunk to it over the network
	 * ({@see \Bitrix\UI\FileUploader\TempFile::create()}), so the assembled bytes are never local at all.
	 * Asking the signature afterwards would mean reading the head back out of the cloud - which is exactly
	 * what File::add does inside addFile, with a timeout and a budget of reads, and ANY obstacle there
	 * leaves the write to the name alone ({@see \Bitrix\Disk\Internal\Service\Restrictions\ContentHeadReader}).
	 * A policy that cannot look must refuse, not wave through; so the door looks here, at the first chunk,
	 * where the bytes are its own and no network stands between them and the question.
	 *
	 * The head is read by this method and handed over as content, instead of letting disk read it from a
	 * path, for the same reason: content that could not be read is a refusal here and not a silence. Disk
	 * cuts the head down to the length its detector asks for, so more of it costs nothing.
	 *
	 * The refusal is the general refusal of an upload, the very code im.v2.File.upload answers with for the
	 * same file - as everywhere else in this service.
	 *
	 * @param string $contentPath Path of the local file holding the beginning of the upload.
	 */
	public function findUploadContentRefusal(string $fileName, string $contentPath): ?Error
	{
		if (!Loader::includeModule('disk'))
		{
			return new Error('Disk module not available', 'FILE_UPLOAD_FAILED');
		}

		$contentHead = $this->readContentHead($contentPath);
		if ($contentHead === null)
		{
			return new Error('File upload failed', 'FILE_UPLOAD_FAILED');
		}

		$options = [];
		$formatPolicyRefusal = Disk\File::findFormatPolicyErrorForUpload(
			['content' => $contentHead],
			$fileName,
			$options,
		);

		return $formatPolicyRefusal === null ? null : new Error('File upload failed', 'FILE_UPLOAD_FAILED');
	}

	/**
	 * The leading bytes of a local file, or null when there are none to be had: an unreadable beginning is
	 * not an empty one, and the caller tells them apart by this null.
	 */
	private function readContentHead(string $path): ?string
	{
		if ($path === '' || !is_file($path))
		{
			return null;
		}

		$handle = @fopen($path, 'rb');
		if ($handle === false)
		{
			return null;
		}

		try
		{
			$head = fread($handle, self::CONTENT_HEAD_LENGTH);
		}
		finally
		{
			fclose($handle);
		}

		return is_string($head) && $head !== '' ? $head : null;
	}

	/**
	 * Asks the portal whether the bytes fit the disk space it allows itself, before a single one is written.
	 *
	 * This is the same question CFile::SaveFile asks a moment later - transformName validates the array of
	 * the file against the very same CDiskQuota with the very same size - so no upload is refused that
	 * would have been accepted before. What changes is the moment, which is now ahead of the temporary file,
	 * and the code of the answer. Without a limit of the portal the check leaves on its first line.
	 *
	 * The limit of the portal is the only lack of space the portal itself owns; the limits disk owns are
	 * read out of the folder afterwards, see findDiskNoSpaceRefusal.
	 *
	 * 🔴 THE EXCEPTION THIS VERY CHECK LEFT STANDING HAS TO GO, or the code of the refusal never reaches the
	 * caller. CDiskQuota throws a CAdminException into $APPLICATION when the portal is over its limit
	 * (\CDiskQuota::GetDiskQuota - a private contract of the kernel: should that throw ever move or stop,
	 * this branch turns into a no-op in silence and the door goes back to answering FILE_UPLOAD_FAILED),
	 * and CRestServer copies whatever stands there over the code of the answer
	 * it is about to send ({@see \Bitrix\Rest\RestException::setApplicationException}): the refusal would
	 * arrive as ERROR_CORE with a localized phrase instead of the name this door promises.
	 *
	 * Only what this call put there is taken away, and that is why the standing exception is compared and
	 * not simply dropped: checkDiskQuota answers false by two different routes, and only one of them throws.
	 * Over the limit, GetDiskQuota throws and answers false. Under the limit but short for this file, the
	 * subtraction alone answers false and NOTHING is thrown - so anything standing in $APPLICATION at that
	 * moment belongs to somebody else, and dropping it would swallow an error this method never caused.
	 *
	 * Nothing is destroyed either way: \CMain::ResetException moves what it clears onto the error stack of
	 * the application, exactly as \CMain::ThrowException already did to whatever the quota displaced.
	 */
	private function findPortalQuotaRefusal(int $contentSize): ?Error
	{
		$application = $GLOBALS['APPLICATION'] ?? null;
		$standingBeforeCheck = $application?->GetException();

		if (\CDiskQuota::getInstance()->checkDiskQuota(['size' => $contentSize]))
		{
			return null;
		}

		if ($application !== null && $application->GetException() !== $standingBeforeCheck)
		{
			$application->ResetException();
		}

		return $this->buildNoSpaceRefusal();
	}

	/**
	 * Reads the cause out of the folder that refused the file and answers the refusal by lack of space, or
	 * null when disk refused for any other reason.
	 *
	 * The collection of the folder is where the cause lives, and it belongs to this very call: uploadFile
	 * clears the collection on its first line, so nothing of an earlier upload can be read here by mistake.
	 *
	 * ERROR_SIZE_RESTRICTION is the one code of disk that means lack of space on this path: the limit on the
	 * size of a storage, which File::addVersion raises and the folder collects, and which im already treats
	 * as lack of space on the path of a copy. The personal quota of an employee is NOT read here, on purpose:
	 * the folder of a chat cannot raise it - its storage is not a personal one - and naming its constant
	 * would tie im to a version of disk that carries it, turning every refusal of the folder into a fatal
	 * on a portal whose disk is older. Should disk ever apply that quota here, the branch is one line.
	 *
	 * The code is named through the class of disk and not as a literal because the caller has just made
	 * Loader::includeModule('disk') answer, so the class is there to resolve.
	 */
	private function findDiskNoSpaceRefusal(Disk\Folder $folder): ?Error
	{
		if ($folder->getErrorByCode(Disk\File::ERROR_SIZE_RESTRICTION) !== null)
		{
			return $this->buildNoSpaceRefusal();
		}

		return null;
	}

	/**
	 * Takes the name from FileError but not the class: this door answers with literal errors of its own, and
	 * a localized phrase beside an English one in the same batch answer would break the contract.
	 */
	private function buildNoSpaceRefusal(): Error
	{
		return new Error('Not enough space on the Drive', FileError::STORAGE_QUOTA_EXCEEDED);
	}

	/**
	 * Quotes a file name of the request back inside the description of a refusal.
	 *
	 * The name is input of the caller and it travels into error_description and from there into the logs
	 * of whoever called: a carriage return in a name would forge a log line, and a name of any length at
	 * all would make the description of the refusal as long as the caller likes. Neither is worth a
	 * refusal of its own - the name is simply cut and stripped of control characters before it is quoted.
	 *
	 * The pattern is deliberately byte oriented: every byte of a multibyte character is above 0x7F, so
	 * nothing of a UTF-8 name is touched, and a name that is no valid UTF-8 cannot make preg_replace
	 * answer null and lose the name altogether.
	 */
	private function quoteFileName(string $fileName): string
	{
		$quoted = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $fileName));

		return mb_substr($quoted, 0, self::QUOTED_FILE_NAME_MAX_LENGTH);
	}

	/**
	 * Refusals a batch earns before a single file of it is touched: its shape and its two limits. None of them
	 * needs a byte on disk, so a batch that cannot be accepted as a whole leaves nothing behind.
	 *
	 * The count comes from FilesValidationService, which calls itself the source of truth for the clients.
	 * The total size is a number of the batch alone - MAX_BATCH_TOTAL_SIZE, see the constant for how it was
	 * measured - and it is well below the ceiling one file is allowed, so the door adds no worst case of its
	 * own by bytes either.
	 *
	 * @param array<int|string, mixed> $files
	 */
	private function validateBatch(array $files): Result
	{
		$result = new Result();

		if ($files === [])
		{
			return $result->addError(new Error('Batch of files is empty', 'FILES_INVALID_FORM'));
		}

		if (count($files) > FilesValidationService::MAX_FILES_COUNT)
		{
			return $result->addError(new Error(
				'Batch carries more files than ' . FilesValidationService::MAX_FILES_COUNT . ' allowed',
				'FILES_LIMIT_EXCEEDED',
			));
		}

		$totalBase64Length = 0;
		foreach ($files as $file)
		{
			if (
				!is_array($file)
				|| !isset($file['name'], $file['content'])
				|| !is_string($file['name'])
				|| !is_string($file['content'])
			)
			{
				return $result->addError(
					new Error('Every file of the batch has to carry a name and a content', 'FILES_INVALID_FORM'),
				);
			}

			// length of the encoded string and not of the bytes behind it: the sum is known before a single decode
			$totalBase64Length += mb_strlen($file['content'], '8bit');
		}

		if ($totalBase64Length > (int)(self::MAX_BATCH_TOTAL_SIZE * 4 / 3))
		{
			return $result->addError(new Error('Total size of the batch exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		return $result;
	}

	public function attachImageToChat(Chat $chat, int $fileId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_NOT_IN_CHAT'));
		}

		$chatFolderId = $chat->getDiskFolderId();
		if ($chatFolderId === null || $chatFolderId <= 0)
		{
			return $result->addError(new Error('Chat folder not found', 'FILE_NOT_IN_CHAT'));
		}

		$file = FileItem::initByDiskFileId($fileId, $chat->getId());
		$diskFile = $file?->getDiskFile();
		if (
			$diskFile === null
			|| (int)$diskFile->getParentId() !== $chatFolderId
			|| $file->getContentType() !== 'image'
		)
		{
			return $result->addError(new Error('Image is not available in chat', 'FILE_NOT_IN_CHAT'));
		}

		$diskFile->increaseGlobalContentVersion();

		return $result->setData(['fileId' => $fileId]);
	}

	/**
	 * Builds the response that carries the file body itself. Every error has to be collected here:
	 * once the response is sent, CFile::viewByUser terminates the process.
	 *
	 * @return Result data: ['response' => Response\File]
	 */
	public function buildDirectDownloadResponse(
		FileItem $file,
		DownloadType $type,
		bool $isCrossOrigin = false,
	): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new FileError(FileError::DISK_NOT_INSTALLED));
		}

		$diskFile = $file->getDiskFile();
		if ($diskFile === null)
		{
			return $result->addError(new FileError(FileError::NOT_FOUND));
		}

		$response = $type === DownloadType::Preview ? $this->buildPreviewResponse($file, $diskFile) : null;
		$response ??= $this->buildOriginalResponse($file, $diskFile, $type);

		if ($response === null)
		{
			return $result->addError(new FileError(FileError::NOT_FOUND));
		}

		// wrapped after the branch is chosen, so the original and every kind of preview go the same way
		if ($isCrossOrigin && $this->isCorsViaPhpEnabled())
		{
			$response = new PhpStreamedFileResponse($response);
		}

		return $result->setData(['response' => $response]);
	}

	private function isCorsViaPhpEnabled(): bool
	{
		return Option::get('im', self::CORS_VIA_PHP_OPTION, 'N') === 'Y';
	}

	/**
	 * Null asks the caller for the original, and both ways of getting it are legitimate: the branch of
	 * the file is the original itself, or disk found no data to build a preview from.
	 */
	private function buildPreviewResponse(FileItem $file, Disk\File $diskFile): ?Response\File
	{
		$source = $file->getPreviewSource();
		if ($source !== PreviewSource::ResizedImage && $source !== PreviewSource::GeneratedPreview)
		{
			return null;
		}

		$response = (new PreviewResponseBuilder())->createByFile(
			$diskFile,
			FileItem::MAX_PREVIEW_IMAGE_SIZE,
			FileItem::MAX_PREVIEW_IMAGE_SIZE,
			'N',
		);

		return $response instanceof Response\File ? $response : null;
	}

	private function buildOriginalResponse(FileItem $file, Disk\File $diskFile, DownloadType $type): ?Response\BFile
	{
		$fileData = \CFile::getFileArray($file->getOriginalFileId());
		if (!is_array($fileData))
		{
			return null;
		}

		$cacheTime = $type === DownloadType::Preview
			? self::PREVIEW_CACHE_TIME
			: Disk\Configuration::DEFAULT_CACHE_TIME
		;

		$response = Response\BFile::createByFileData($fileData, $diskFile->getName());
		$response->setCacheTime($cacheTime);

		return $response;
	}

	public function resolveDownloadUrl(
		FileItem $file,
		?\CRestServer $restServer = null,
	): Result
	{
		$result = new Result();

		$diskFile = $file->getDiskFile();
		if ($diskFile === null)
		{
			return $result->addError(new Error('File not found', 'FILE_NOT_FOUND'));
		}

		// Ahead of the branching on purpose: the ajax branch takes the address from the serialization, where
		// a restricted video note already has an empty urlDownload, and the refusal would degrade into an
		// empty string instead of a public error code.
		if (
			$file->isActualVideoNote()
			&& !Locator::getVideoNoteDownloadPolicy()->isAllowed(User::getCurrent())
		)
		{
			return $result->addError(new FileError(FileError::VIDEO_NOTE_DOWNLOAD_RESTRICTED));
		}

		if ($restServer === null)
		{
			$result->setData(['downloadUrl' => $file->toRestFormat()['urlDownload'] ?? '']);

			return $result;
		}

		$downloadUrl = \CRestUtil::getDownloadUrl(['id' => $diskFile->getId()], $restServer);

		$result->setData(['downloadUrl' => $downloadUrl]);

		return $result;
	}
}
