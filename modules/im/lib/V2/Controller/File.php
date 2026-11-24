<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckDiskFileAccess;
use Bitrix\Im\V2\Controller\Filter\CheckThreadPostAccess;
use Bitrix\Im\V2\Controller\Filter\CheckVideoNoteDownloadPolicy;
use Bitrix\Im\V2\Controller\Filter\DenyBatchCall;
use Bitrix\Im\V2\Controller\Filter\DenyBatchCallWithFiles;
use Bitrix\Im\V2\Entity\File\DownloadType;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;

/**
 * File controller for im.v2.File.* methods.
 *
 * Provides single-call file upload to chat (replaces legacy 3-step flow:
 * im.disk.folder.get → disk.folder.uploadFile → im.disk.file.commit).
 */
class File extends BaseController
{
	public function configureActions(): array
	{
		return [
			'upload' => [
				'+prefilters' => [
					// the batch form multiplies the work of one HTTP request by the length of a REST
					// batch, and this refuses that composition alone: the single form inside a batch
					// keeps working exactly as it does today
					new DenyBatchCallWithFiles(),
					// a file goes into a discussion by the very rules a message does, so it is refused
					// by the same reasons and has to name them alike
					new CheckThreadPostAccess(),
					new CheckActionAccess(Action::Send),
				],
			],
			'uploadToFolder' => [
				'+prefilters' => [
					new DenyBatchCallWithFiles(),
					// the folder is the same door as the chat: a file put there lands in the
					// discussion just as an uploaded one does, so it is refused by the same reasons
					new CheckThreadPostAccess(),
					new CheckActionAccess(Action::Send),
				],
			],
			'download' => [
				'+prefilters' => [
					new CheckDiskFileAccess(),
					new CheckVideoNoteDownloadPolicy(),
				],
			],
			'directDownload' => [
				'+prefilters' => [
					new DenyBatchCall(),
					new CheckDiskFileAccess(),
					new CheckVideoNoteDownloadPolicy(),
				],
			],
		];
	}

	public function getAutoWiredParameters()
	{
		return array_merge(parent::getAutoWiredParameters(), [
			new ExactParameter(
				FileItem::class,
				'file',
				function ($className, int $fileId) {
					return FileItem::initByDiskFileId($fileId);
				}
			),
		]);
	}

	/**
	 * Takes either one file - fields.name with fields.content - or a batch of them in fields.files, and the
	 * form of the request chooses the form of the answer. A request without fields.files goes the way it went
	 * before this branch existed and gets the answer it always got, down to the last key.
	 *
	 * @restMethod im.v2.File.upload
	 */
	public function uploadAction(
		Chat $chat,
		array $fields,
		CurrentUser $currentUser,
	): ?array
	{
		if (isset($fields['files']))
		{
			return $this->uploadBatchToChat($chat, $fields, $currentUser);
		}

		$result = (new FileService())->uploadFileToChat(
			$chat,
			$fields['name'] ?? '',
			$fields['content'] ?? '',
			(int)$currentUser->getId(),
			$fields['message'] ?? null,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * All or nothing, because a message is seen by others the moment it appears and a partly published batch
	 * has no correct retry: the caller would send the rest as a second message, the very outcome the batch
	 * exists to remove. So any refused file refuses the whole call, and the answer of the service - files,
	 * messageId, chatId, dialogId - is passed on as it is.
	 *
	 * @return array|null data of the batch, or null when the call was refused and the errors were collected
	 */
	private function uploadBatchToChat(Chat $chat, array $fields, CurrentUser $currentUser): ?array
	{
		$formRefusal = $this->refuseMixedUploadForm($fields);
		if ($formRefusal !== null)
		{
			$this->addError($formRefusal);

			return null;
		}

		$result = (new FileService())->uploadFilesToChat(
			$chat,
			$fields['files'],
			(int)$currentUser->getId(),
			$fields['message'] ?? null,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Puts the bytes into the folder of the chat and stops there: no message is published, so the answer
	 * carries no messageId. The file stays at content version 1, which the client reads as status "upload";
	 * im.disk.file.commit with UPLOAD_ID[] publishes it later and raises the status to "done".
	 *
	 * Takes either one file - fields.name with fields.content - or a batch of them in fields.files, and the
	 * form of the request chooses the form of the answer. A request without fields.files goes the way it went
	 * before this branch existed and gets the answer it always got, down to the last key.
	 *
	 * @restMethod im.v2.File.uploadToFolder
	 */
	public function uploadToFolderAction(
		Chat $chat,
		array $fields,
		CurrentUser $currentUser,
	): ?array
	{
		if (isset($fields['files']))
		{
			return $this->uploadBatchToChatFolder($chat, $fields, $currentUser);
		}

		$result = (new FileService())->uploadFileToChatFolder(
			$chat,
			$fields['name'] ?? '',
			$fields['content'] ?? '',
			(int)$currentUser->getId(),
			false,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$chatId = $chat->getId();
		$fileId = (int)$result->getData()['fileId'];

		return [
			'file' => FileItem::initByDiskFileId($fileId, $chatId)?->toRestFormat(),
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		];
	}

	/**
	 * Best effort, because nothing is published: a file in the folder has reached no other eyes, so the caller
	 * is the one to decide whether nine out of ten is enough and to publish what it got through
	 * im.disk.file.commit. The answer stays successful while at least one file landed and carries what did not
	 * in errors, each entry naming the key the caller sent it under.
	 *
	 * 🔴 A partial refusal therefore arrives as a SUCCESSFUL answer with a non-empty errors: a client that
	 * reads only the error field would take nine out of ten for ten. A batch where not a single file landed is
	 * the opposite case and comes back as a plain REST error, together with every refusal of the request
	 * itself - rights, chat, limits, form.
	 *
	 * @return array|null files, errors, chatId and dialogId, or null when the call itself was refused
	 */
	private function uploadBatchToChatFolder(Chat $chat, array $fields, CurrentUser $currentUser): ?array
	{
		$formRefusal = $this->refuseMixedUploadForm($fields);
		if ($formRefusal !== null)
		{
			$this->addError($formRefusal);

			return null;
		}

		$files = $fields['files'];
		$result = (new FileService())->uploadFilesToChatFolder(
			$chat,
			$files,
			(int)$currentUser->getId(),
			false,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$chatId = $chat->getId();
		$data = $result->getData();

		/** @var array<int|string, int> $acceptedIds file id under the key of the request */
		$acceptedIds = $data['files'];
		/** @var array<int|string, Error> $errors */
		$errors = $data['errors'];

		// one query for the whole batch instead of one per file, with the params of every file warmed in
		// the same go; a file whose model does not come back is simply absent from the collection
		$restFilesById = [];
		foreach (FileCollection::initByDiskFilesIds(array_values($acceptedIds), $chatId)->toRestFormat() as $restFile)
		{
			$restFilesById[(int)$restFile['id']] = $restFile;
		}

		$acceptedFiles = [];
		$refusedFiles = [];

		// walked in the order of the request, so both lists of the answer follow it
		foreach (array_keys($files) as $requestKey)
		{
			if (isset($errors[$requestKey]))
			{
				$refusedFiles[] = $this->describeRefusedFile($requestKey, $files, $errors[$requestKey]);

				continue;
			}

			$restFile = $restFilesById[(int)($acceptedIds[$requestKey] ?? 0)] ?? null;
			if ($restFile === null)
			{
				// the bytes did land, but the model cannot be read back. Dropping the file from the answer
				// would hide from the caller a file it now owns and has no identifier to publish, so the
				// file is named a refusal instead of disappearing
				$refusedFiles[] = $this->describeRefusedFile(
					$requestKey,
					$files,
					new Error('Could not read the uploaded file', 'FILE_UPLOAD_FAILED'),
				);

				continue;
			}

			$acceptedFiles[] = $restFile;
		}

		return [
			'files' => $acceptedFiles,
			'errors' => $refusedFiles,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		];
	}

	/**
	 * The key of the request is what the caller matches a refusal by, so it travels as index, and the name
	 * comes from the very entry that key points at. The name stays a field of its own and is never glued
	 * into a text: the json encoder answers for its escaping, and a caller reading the field knows what it
	 * holds.
	 *
	 * @return array{index: int|string, name: string, code: string, message: string}
	 */
	private function describeRefusedFile(int|string $requestKey, array $files, Error $error): array
	{
		return [
			'index' => $requestKey,
			'name' => (string)($files[$requestKey]['name'] ?? ''),
			'code' => (string)$error->getCode(),
			'message' => $error->getMessage(),
		];
	}

	/**
	 * The presence of fields.files is the only discriminator of the two forms, so a request that carries both
	 * at once is a broken contract of the caller and not a choice for the door to make on its own.
	 *
	 * The refusal is a literal error of this very door, as every refusal of the upload already is: FileError
	 * is the other family, the one that takes its description from Loc, and a localized phrase next to an
	 * English one in the same answer would be a defect of the contract.
	 *
	 * @return Error|null the refusal of the form, or null when the batch form stands alone and is well shaped
	 */
	private function refuseMixedUploadForm(array $fields): ?Error
	{
		if (!is_array($fields['files']))
		{
			return new Error('The field files has to carry a list of files', 'FILES_INVALID_FORM');
		}

		if (isset($fields['name']) || isset($fields['content']))
		{
			return new Error(
				'A request carries either files or name with content, never both at once',
				'FILES_INVALID_FORM',
			);
		}

		return null;
	}

	/**
	 * @restMethod im.v2.File.download
	 */
	public function downloadAction(
		?FileItem $file,
		?\CRestServer $restServer = null,
	): ?array
	{
		if ($file === null)
		{
			$this->addError(new \Bitrix\Main\Error('File not found', 'FILE_NOT_FOUND'));

			return null;
		}

		$result = (new FileService())->resolveDownloadUrl($file, $restServer);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Answers with the body of the file itself, not with a link to it: an external client on a
	 * foreign domain has no session cookie of the portal and cannot follow the internal links.
	 *
	 * $restServer is never read here: it is the argument DenyBatchCall looks for.
	 *
	 * @restMethod im.v2.File.directDownload
	 */
	public function directDownloadAction(
		?FileItem $file,
		?string $type = null,
		?\CRestServer $restServer = null,
	): ?Response\File
	{
		if ($file === null)
		{
			$this->addError(new FileError(FileError::NOT_FOUND));

			return null;
		}

		$downloadType = $type === null ? DownloadType::Full : DownloadType::tryFrom($type);
		if ($downloadType === null)
		{
			$this->addError(new FileError(FileError::UNKNOWN_DOWNLOAD_TYPE));

			return null;
		}

		$result = (new FileService())->buildDirectDownloadResponse(
			$file,
			$downloadType,
			$this->isCrossOriginRequest(),
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData()['response'];
	}

	/**
	 * A browser names the page behind a script with Origin. The literal "null" of a sandbox or a local page
	 * counts too: such a script reads the answer under the same rules.
	 */
	private function isCrossOriginRequest(): bool
	{
		return (string)$this->getRequest()->getHeader('Origin') !== '';
	}
}
