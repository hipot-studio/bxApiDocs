<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Disk\File as DiskFile;
use Bitrix\Im\Message\Uuid;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\IO\File as IoFile;
use Bitrix\Main\Loader;

final class VCardConversionService
{
	private const MAX_VCARD_SIZE = 1024 * 1024;
	private const LOGGER_ID = 'vcard_conversion';

	private VCardReader $reader;
	private ContactCardService $contactCardService;
	private FileService $fileService;
	private \Closure $publisher;

	public function __construct(
		?VCardReader $reader = null,
		?ContactCardService $contactCardService = null,
		?FileService $fileService = null,
		?\Closure $publisher = null,
	)
	{
		$this->reader = $reader ?? new VCardReader();
		$this->fileService = $fileService ?? new FileService();
		$this->contactCardService = $contactCardService ?? new ContactCardService($this->fileService);
		$this->publisher = $publisher ?? static fn (
			int $chatId,
			array $fileTokens,
			string $message,
			array $options,
		): array|false => \CIMDisk::UploadFileFromDisk($chatId, $fileTokens, $message, $options);
	}

	public function tryConvert(
		Chat $chat,
		array $fileTokens,
		?string $messageText,
		array &$uploadOptions,
		int $actorUserId,
	): array|false|null
	{
		if (count($fileTokens) !== 1 || !Features::isContactCardAvailable())
		{
			return null;
		}

		$token = $this->parseFileToken($fileTokens[0]);
		if ($token === null || !Loader::includeModule('disk'))
		{
			return null;
		}

		$chatFolderId = $chat->getDiskFolderId();
		if ($chatFolderId === null || $chatFolderId <= 0)
		{
			return null;
		}

		$diskFile = \CIMDisk::getFileModel($token['fileId'], $actorUserId);
		if ($diskFile === null || (int)$diskFile->getParentId() !== $chatFolderId)
		{
			return null;
		}

		// $uploadOptions is shared with the ordinary file path, so a rejected model must never reach it
		$uploadOptions['PRELOADED_FILE_MODELS'][$token['original']] = $diskFile;

		if (!ChatTypePolicy::isAllowed($chat))
		{
			return null;
		}

		$fileSize = (int)$diskFile->getSize();
		if ($fileSize > self::MAX_VCARD_SIZE || mb_strtolower((string)$diskFile->getExtension()) !== 'vcf')
		{
			return null;
		}

		$chat = $chat->withContextUser($actorUserId);
		if (!$chat->canDo(Action::Send))
		{
			throw new AccessDeniedException();
		}

		$uuidReservation = $this->reserveMessageUuid((string)($uploadOptions['TEMPLATE_ID'] ?? ''));
		if (is_int($uuidReservation))
		{
			return $this->buildExistingPublishResult(
				$chat,
				$uuidReservation,
				$token['original'],
				$actorUserId,
			);
		}
		if ($uuidReservation === false)
		{
			return false;
		}
		if ($uuidReservation instanceof Uuid)
		{
			$uploadOptions['MESSAGE_UUID_RESERVATION'] = $uuidReservation;
		}

		try
		{
			$content = $this->readFileContent($diskFile);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('read', $chat, $token['fileId'], $fileSize, exception: $exception);

			return null;
		}

		if (
			$content === null
			|| strlen($content) > self::MAX_VCARD_SIZE
			|| !$this->startsWithVCardMarker($content)
		)
		{
			return null;
		}

		$readResult = $this->reader->read($content);
		if (!$readResult->isSuccess())
		{
			$this->logFailure(
				'parse',
				$chat,
				$token['fileId'],
				strlen($content),
				$this->getErrorCodes($readResult),
			);

			return null;
		}

		$records = $readResult->getResult();
		if (!is_array($records) || count($records) !== 1 || !is_array($records[0]))
		{
			return null;
		}

		$record = $records[0];
		$contact = $this->prepareContactInput($record);
		$preparedResult = $this->contactCardService->prepare($contact, $chat, $messageText);
		if (!$preparedResult->isSuccess())
		{
			return null;
		}

		$prepared = $preparedResult->getResult();
		if ($uuidReservation instanceof Uuid && !$uuidReservation->renewReservation())
		{
			return false;
		}

		$generatedPhoto = null;
		$embeddedPhoto = $record['embeddedPhoto'] ?? null;
		if (is_string($embeddedPhoto) && $embeddedPhoto !== '')
		{
			$photoExtension = $this->detectImageExtension($embeddedPhoto);
			if ($photoExtension !== null)
			{
				$generatedPhoto = $this->uploadPhoto(
					$chat,
					$embeddedPhoto,
					$photoExtension,
					$actorUserId,
				);
			}
		}

		$params = is_array($uploadOptions['PARAMS'] ?? null) ? $uploadOptions['PARAMS'] : [];
		$serverParams = is_array($prepared['PARAMS'] ?? null) ? $prepared['PARAMS'] : [];
		$uploadOptions['PARAMS'] = array_replace($params, $serverParams);
		$uploadOptions['AS_FILE'] = 'N';

		$publishTokens = [$token['original']];
		if ($generatedPhoto !== null)
		{
			$photoToken = 'upload' . $generatedPhoto->getId();
			$publishTokens[] = $photoToken;
			$uploadOptions['PARAMS'][Params::FILE_ID] = [(int)$generatedPhoto->getId()];
			$uploadOptions['PRELOADED_FILE_MODELS'][$photoToken] = $generatedPhoto;
		}

		try
		{
			$publishResult = ($this->publisher)(
				$chat->getId(),
				$publishTokens,
				(string)($prepared['MESSAGE'] ?? ''),
				$uploadOptions,
			);
		}
		catch (\Throwable $exception)
		{
			if ($generatedPhoto !== null)
			{
				$this->deleteGeneratedPhoto($generatedPhoto, $actorUserId, $chat, $fileSize);
			}
			$uuidReservation?->deleteIfPending();

			throw $exception;
		}
		if ($publishResult === false)
		{
			if ($generatedPhoto !== null)
			{
				$this->deleteGeneratedPhoto($generatedPhoto, $actorUserId, $chat, $fileSize);
			}
			$uuidReservation?->deleteIfPending();

			return false;
		}

		return $publishResult;
	}

	private function reserveMessageUuid(string $uuid): Uuid|int|false|null
	{
		if (!Uuid::validate($uuid))
		{
			return null;
		}

		$reservation = new Uuid($uuid);
		if ($reservation->add())
		{
			return $reservation;
		}

		$messageId = $reservation->getMessageId();
		if ($messageId !== null)
		{
			return $messageId;
		}
		if ($reservation->updateIfExpired())
		{
			return $reservation;
		}

		return false;
	}

	private function buildExistingPublishResult(
		Chat $chat,
		int $messageId,
		string $sourceToken,
		int $actorUserId,
	): array|false
	{
		$message = new Message($messageId);
		if (
			$message->getChatId() !== $chat->getId()
			|| !$message->checkAccess($actorUserId)->isSuccess()
		)
		{
			return false;
		}

		$result = [
			'FILES' => [],
			'DISK_ID' => [],
			'FILE_MODELS' => [],
			'MESSAGE_ID' => $messageId,
		];
		$fileIds = array_map('intval', $message->getFileIds());
		$sourceFileId = str_starts_with($sourceToken, 'upload') ? (int)substr($sourceToken, 6) : null;
		$fileModels = [];
		foreach ($fileIds as $fileId)
		{
			$fileModels[$fileId] = \CIMDisk::getFileModel($fileId, $actorUserId);
		}
		usort(
			$fileIds,
			static function (int $leftId, int $rightId) use ($sourceFileId, $fileModels): int
			{
				$leftIsSource = $leftId === $sourceFileId
					|| mb_strtolower((string)$fileModels[$leftId]?->getExtension()) === 'vcf';
				$rightIsSource = $rightId === $sourceFileId
					|| mb_strtolower((string)$fileModels[$rightId]?->getExtension()) === 'vcf';

				return (int)$rightIsSource <=> (int)$leftIsSource;
			},
		);
		foreach ($fileIds as $index => $fileId)
		{
			$fileToken = $index === 0 ? $sourceToken : 'upload' . $fileId;
			$fileModel = $fileModels[$fileId];
			$result['DISK_ID'][] = $fileId;
			if ($fileModel === null)
			{
				$result['FILES'][$fileToken]['id'] = 0;

				continue;
			}

			$result['FILE_MODELS'][$fileToken] = $fileModel;
			$result['FILES'][$fileToken] = \CIMDisk::GetFileParams($chat->getId(), $fileModel);
		}

		return $result;
	}

	private function parseFileToken(mixed $token): ?array
	{
		if (!is_string($token) || preg_match('/\A(disk|upload)([1-9][0-9]*)\z/D', $token, $matches) !== 1)
		{
			return null;
		}

		return [
			'original' => $token,
			'fileId' => (int)$matches[2],
		];
	}

	private function readFileContent(DiskFile $diskFile): ?string
	{
		$fileArray = \CFile::MakeFileArray((int)$diskFile->getFileId());
		if (!is_array($fileArray))
		{
			return null;
		}

		if (array_key_exists('content', $fileArray) && is_string($fileArray['content']))
		{
			return $fileArray['content'];
		}

		$tmpName = $fileArray['tmp_name'] ?? null;
		if (!is_string($tmpName) || $tmpName === '')
		{
			return null;
		}

		return IoFile::getFileContents($tmpName);
	}

	private function startsWithVCardMarker(string $content): bool
	{
		if (str_starts_with($content, "\xEF\xBB\xBF"))
		{
			$content = substr($content, 3);
		}

		$lineLength = strcspn($content, "\r\n");
		$firstLine = substr($content, 0, $lineLength);

		return strcasecmp(trim($firstLine), 'BEGIN:VCARD') === 0;
	}

	private function prepareContactInput(array $record): array
	{
		unset($record['embeddedPhoto']);
		foreach (['firstName', 'lastName', 'displayName', 'organization'] as $field)
		{
			if (($record[$field] ?? null) === null)
			{
				unset($record[$field]);
			}
		}

		return $record;
	}

	private function detectImageExtension(string $content): ?string
	{
		$imageInfo = @getimagesizefromstring($content);
		$imageType = is_array($imageInfo) ? (int)($imageInfo[2] ?? 0) : 0;

		return match ($imageType)
		{
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG => 'png',
			IMAGETYPE_GIF => 'gif',
			IMAGETYPE_BMP => 'bmp',
			IMAGETYPE_WEBP => 'webp',
			default => null,
		};
	}

	private function uploadPhoto(
		Chat $chat,
		string $content,
		string $extension,
		int $actorUserId,
	): ?DiskFile
	{
		try
		{
			$fileName = 'contact-photo-' . bin2hex(random_bytes(8)) . '.' . $extension;
			$result = $this->fileService->uploadContentToChatFolder(
				$chat,
				$fileName,
				$content,
				$actorUserId,
				false,
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$result->isSuccess())
		{
			return null;
		}

		$photo = $result->getData()['fileModel'] ?? null;

		return $photo instanceof DiskFile ? $photo : null;
	}

	private function deleteGeneratedPhoto(
		DiskFile $photo,
		int $actorUserId,
		Chat $chat,
		int $sourceFileSize,
	): void
	{
		try
		{
			if ($photo->delete($actorUserId))
			{
				return;
			}

			$this->logFailure(
				'photo_cleanup',
				$chat,
				(int)$photo->getId(),
				$sourceFileSize,
				$this->getErrorCodes($photo),
			);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(
				'photo_cleanup',
				$chat,
				(int)$photo->getId(),
				$sourceFileSize,
				exception: $exception,
			);
		}
	}

	private function getErrorCodes(object $result): array
	{
		if (!method_exists($result, 'getErrors'))
		{
			return [];
		}

		$errorCodes = [];
		foreach ($result->getErrors() as $error)
		{
			$errorCodes[] = (string)$error->getCode();
		}

		return $errorCodes;
	}

	private function logFailure(
		string $stage,
		Chat $chat,
		int $diskFileId,
		int $size,
		array $errorCodes = [],
		?\Throwable $exception = null,
	): void
	{
		$context = [
			'stage' => $stage,
			'chatId' => $chat->getId(),
			'diskFileId' => $diskFileId,
			'size' => $size,
		];
		if (!empty($errorCodes))
		{
			$context['errorCodes'] = $errorCodes;
		}
		if ($exception !== null)
		{
			$context['exceptionClass'] = $exception::class;
		}

		(new Logger(self::LOGGER_ID))->logArray($context);
	}
}
