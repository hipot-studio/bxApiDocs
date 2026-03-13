<?php

namespace Bitrix\Call;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Call\Model\EO_CallTrack;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Cache\ExternalAccessTokenManager;


class Track extends EO_CallTrack
{
	public const
		TYPE_RECORD = 'record',
		TYPE_TRACK_PACK = 'track_pack',
		TYPE_VIDEO_RECORD = 'video_record',
		TYPE_VIDEO_PREVIEW = 'video_preview'
	;

	/**
	 * Attach file from filesystem path to this track
	 *
	 * @param string $filePath Path to file (can be temp or permanent)
	 * @param string|null $mimeType MIME type (if null, uses track's FileMimeType)
	 * @return Result Contains fileId in data on success
	 */
	public function attachFileFromPath(string $filePath, ?string $mimeType = null): Result
	{
		$result = new Result();

		// Use provided mime type or fall back to track's mime type
		$mimeType = $mimeType ?: $this->getFileMimeType();

		// Create file array from path
		$attachFile = \CFile::makeFileArray($filePath, $mimeType);
		if (empty($attachFile))
		{
			return $result->addError(new TrackError(
				TrackError::FILE_ARRAY_ERROR,
				'Could not create file array from path: ' . $filePath
			));
		}

		// Set metadata
		$attachFile['MODULE_ID'] = 'call';
		if ($this->getFileName())
		{
			$attachFile['name'] = $this->getFileName();
			$attachFile['ORIGINAL_NAME'] = $this->getFileName();
		}

		// Save file to storage
		$fileId = \CFile::saveFile($attachFile, 'call');
		if (!$fileId)
		{
			return $result->addError(new TrackError(
				TrackError::SAVE_FILE_ERROR,
				'Could not save file to disk'
			));
		}

		// Update track with file ID and size
		$this
			->setFileId($fileId)
			->setFileSize((int)$attachFile['size']);

		return $result->setData(['fileId' => $fileId, 'fileSize' => (int)$attachFile['size']]);
	}

	public function attachTempFile(): Result
	{
		$tempPath = $this->getTempPath();
		if (!$tempPath)
		{
			return (new Result())->addError(new TrackError(
				TrackError::SAVE_FILE_ERROR,
				'Temp path is not set'
			));
		}

		$result = $this->attachFileFromPath($tempPath);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if ($tempPath)
		{
			self::scheduleTempCleanup($tempPath);
		}

		$this
			->setTempPath(null)
			->setDownloadUrl(null)
			->setDownloaded(true)
			->save();

		return $result;
	}

	public function attachToDisk(): Result
	{
		$result = new Result();

		$chatId = $this->fillCall()?->getChatId();
		if ($chatId)
		{
			$callInitiatorId = $this->getCall()->getInitiatorId();

			if ($this->getDiskFileId())
			{
				$diskFileId = $this->getDiskFileId();
			}
			else
			{
				if (!Loader::includeModule('im') || !Loader::includeModule('disk'))
				{
					return $result->addError(
						new TrackError(TrackError::DISK_ATTACH_ERROR, 'Can not put file on chat disk')
					);
				}
				$diskFileId = \CIMDisk::UploadFileFromMain($chatId, [$this->getFileId()], $callInitiatorId)[0];
			}

			if ($diskFileId)
			{
				$this->setDiskFileId($diskFileId)->save();

				$type = match (true)
				{
					$this->getType() == self::TYPE_RECORD => \Bitrix\Im\V2\Link\File\FileItem::AUDIO_SUBTYPE,
					$this->getType() == self::TYPE_VIDEO_RECORD => \Bitrix\Im\V2\Link\File\FileItem::MEDIA_SUBTYPE,
					$this->getType() == self::TYPE_VIDEO_PREVIEW => \Bitrix\Im\V2\Link\File\FileItem::MEDIA_SUBTYPE,
					str_contains($this->getFileMimeType(), 'audio/') => \Bitrix\Im\V2\Link\File\FileItem::AUDIO_SUBTYPE,
					str_contains($this->getFileMimeType(), 'video/') => \Bitrix\Im\V2\Link\File\FileItem::MEDIA_SUBTYPE,
					default => \Bitrix\Im\V2\Link\File\FileItem::OTHER_SUBTYPE
				};

				$file = \Bitrix\Im\V2\Entity\File\FileItem::initByDiskFileId($diskFileId);
				$link = (new \Bitrix\Im\V2\Link\File\FileItem)
					->setSubtype($type)
					->setAuthorId($callInitiatorId)
					->setChatId($this->getCall()->getChatId())
					->setEntity($file)
				;
				if ($link->save()->isSuccess())
				{
					\Bitrix\Im\V2\Link\Push::getInstance()
						->sendFull($link, 'fileAdd', ['CHAT_ID' => $link->getChatId()]);
				}

				$result->setData(['diskFileId' => $diskFileId]);
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	public function drop(): Result
	{
		$deleted = false;
		if ($this->getDiskFileId())
		{
			if (Loader::includeModule('im'))
			{
				(new \Bitrix\Im\V2\Link\File\FileService())->deleteFilesByDiskFileId($this->getDiskFileId());
			}
			if (Loader::includeModule('disk'))
			{
				$diskFile = \Bitrix\Disk\File::getById($this->getDiskFileId());
				if ($diskFile instanceof \Bitrix\Disk\File)
				{
					$deletedBy = $this->fillCall()?->getInitiatorId() ?? CurrentUser::get()->getId();
					$deleted = $diskFile->delete($deletedBy);
				}
			}
		}

		if (!$deleted && $this->getFileId())
		{
			\CFile::Delete($this->getFileId());
		}

		if ($this->getExternalTrackId())
		{
			(new ControllerClient())->dropTrack($this);
		}

		return $this->delete();
	}

	/**
	 * @see \Bitrix\Call\Controller\Track::downloadAction
	 * @param bool $absolute
	 * @param bool $forceDownload
	 * @return string
	 */
	public function getUrl(bool $absolute = true, bool $forceDownload = false, bool $isExternalLink = false): string
	{
		$params = [
			'callId' => $this->getCallId(),
			'trackId' => $this->getId(),
		];
		if ($forceDownload)
		{
			$params['forceDownload'] = 1;
		}

		if ($isExternalLink)
		{
			$params['token'] = ExternalAccessTokenManager::generateToken($this->getId(), $this->getCallId());
		}

		$url = UrlManager::getInstance()->create(
			'call.Track.download',
			[
				'signedParameters' => \Bitrix\Main\Component\ParameterSigner::signParameters(
					'call.Track.download',
					$params
				),
			],
			$absolute
		);

		if ($absolute && Loader::includeModule('ai'))
		{
			$publicUrl = \Bitrix\AI\Config::getValue('public_url') ?? '';
			if (!empty($publicUrl))
			{
				$parsed = parse_url($publicUrl);
				$url = $url->withScheme($parsed['scheme'])->setHost($parsed['host']);
			}
		}

		return $url->getLocator();
	}

	public function toRestFormat(): array
	{
		return [
			'trackId' => $this->getId(),
			'type' => $this->getType(),
			'fileId' => $this->getFileId(),
			'diskFileId' => $this->getDiskFileId(),
			'duration' => $this->getDuration(),
			'fileSize' => $this->getFileSize(),
			'fileName' => $this->getFileName(),
			'mimeType' => $this->getFileMimeType(),
			'callId' => $this->getCallId(),
			'relUrl' => $this->getUrl(false),
			'url' => $this->getUrl(true, true),
			'dateCreate' => $this->getDateCreate(),
		];
	}

	public function toArray(): array
	{
		return [
			'TRACK_ID' => $this->getId(),
			'TYPE' => $this->getType(),
			'FILE_ID' => $this->getFileId(),
			'DISK_FILE_ID' => $this->getDiskFileId(),
			'DURATION' => $this->getDuration(),
			'FILE_SIZE' => $this->getFileSize(),
			'FILE_NAME' => $this->getFileName(),
			'MIME_TYPE' => $this->getFileMimeType(),
			'CALL_ID' => $this->getCallId(),
			'REL_URL' => $this->getUrl(false),
			'URL' => $this->getUrl(true, true),
			'DATE_CREATE' => $this->getDateCreate(),
		];
	}

	public function generateFilename(): self
	{
		$callId = $this->getCallId();
		$externalId = $this->getExternalTrackId();

		if ($this->getType() == self::TYPE_TRACK_PACK)
		{
			$this->setFileName("track-pack-{$callId}-{$externalId}.zip");
		}
		elseif ($this->getType() == self::TYPE_RECORD)
		{
			$fileName =
				Loc::getMessage('CALL_TRACK_RECORD_FILE_NAME', [
					'#CALL_ID#' => $callId,
					'#CALL_START#' => (new DateTime())->format('Y-m-d')
				])
				. ".ogg";

			$this->setFileName($fileName ?: "composed-{$callId}.ogg");
		}
		elseif ($this->getType() == self::TYPE_VIDEO_RECORD)
		{
			$isVideo = str_starts_with($this->getFileMimeType(), 'video/');
			$fileName =
				Loc::getMessage('CALL_TRACK_RECORD_FILE_NAME', [
					'#CALL_ID#' => $callId,
					'#CALL_START#' => (new DateTime())->format('Y-m-d')
				])
				. ($isVideo ? ".mp4" : ".ogg");

			$this->setFileName($fileName ?: "composed-{$callId}.mp4");
		}
		elseif ($this->getType() == self::TYPE_VIDEO_PREVIEW)
		{
			$extension = $this->getFileExtensionFromMimeType() ?: 'jpg';

			$fileName =
				'preview_' . Loc::getMessage('CALL_TRACK_RECORD_FILE_NAME', [
					'#CALL_ID#' => $callId,
					'#CALL_START#' => (new DateTime())->format('Y-m-d')
				])
				. ".{$extension}";

			$this->setFileName($fileName ?: "preview-{$callId}.{$extension}");
		}
		elseif (!$this->getFileName())
		{
			$this->setFileName("record-{$externalId}");
		}

		return $this;
	}

	/**
	 * Get file extension based on MIME type
	 *
	 * @return string|null
	 */
	private function getFileExtensionFromMimeType(): ?string
	{
		$mimeType = $this->getFileMimeType();
		if (!$mimeType) {
			return null;
		}

		$mimeToExtension = [
			'image/jpeg' => 'jpg',
			'image/jpg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/bmp' => 'bmp',
			'image/tiff' => 'tiff',
			'image/svg+xml' => 'svg',
		];

		return $mimeToExtension[$mimeType] ?? null;
	}

	/**
	 * Check if directory is empty
	 */
	private static function isDirectoryEmpty(\Bitrix\Main\IO\Directory $dir): bool
	{
		return empty($dir->getChildren());
	}

	/**
	 * Schedule temp file cleanup via background job
	 */
	public static function scheduleTempCleanup(string $tempPath): void
	{
		Application::getInstance()->addBackgroundJob(
			[self::class, 'cleanupTempFile'],
			['tempPath' => $tempPath],
			Application::JOB_PRIORITY_LOW
		);
	}

	/**
	 * Clean up temp file and its empty parent directory
	 */
	public static function cleanupTempFile(string $tempPath): void
	{
		if (empty($tempPath))
		{
			return;
		}

		$tempFile = new \Bitrix\Main\IO\File($tempPath);
		if ($tempFile->isExists())
		{
			$tempFile->delete();
		}

		$tempDir = $tempFile->getDirectory();
		if ($tempDir->isExists() && self::isDirectoryEmpty($tempDir))
		{
			$tempDir->delete();
		}
	}

	/**
	 * @deprecated
	 * @see self::scheduleTempCleanup
	 * @param string $tempPath
	 * @return string
	 */
	public static function cleanupTempFileAgent(string $tempPath): string
	{
		self::cleanupTempFile($tempPath);
		return '';
	}

	/**
	 * @return string
	 */
	public function generateTemporaryPath(): self
	{
		$tempDir = \CTempFile::GetDirectoryName(24); // Keep for 24 hours
		$tempFilePath = \Bitrix\Main\Security\Random::getString(20);
		$this->setTempPath($tempDir . $tempFilePath);

		return $this;
	}

	public static function getTrackForCall(int $callId, string $type): ?self
	{
		return CallTrackTable::getList([
			'select' => ['*'],
			'filter' => [
				'=CALL_ID' => $callId,
				'=TYPE' => $type,
			],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->fetchObject();
	}
}
