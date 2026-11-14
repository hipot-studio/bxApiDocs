<?php

namespace Bitrix\Call;

use Bitrix\Call\Background\JobDispatcher;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Model\EO_CallTrack;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Cache\ExternalAccessTokenManager;
use Bitrix\Call\Integration\AI\CallAISettings;


class Track extends EO_CallTrack
{
	public const
		TYPE_RECORD = 'record',
		TYPE_TRACK_PACK = 'track_pack',
		TYPE_VIDEO_RECORD = 'video_record',
		TYPE_VIDEO_PREVIEW = 'video_preview'
	;

	/**
	 * Outcome of a degradation, not a default of the normal path: the format was not recognized and
	 * the name carried nothing usable. Retargeting it at the extension of a new format would mirror
	 * the defect this rule fixes - every unrecognized recording would then be named after that one.
	 */
	private const FALLBACK_FILE_EXTENSION = 'ogg';

	/**
	 * Keys are normalized mime types (lowercase, no parameters): receivers normalize before a track is saved.
	 * An extension belongs here only if disk classifies it as the intended file type.
	 */
	private const EXTENSION_BY_MIME_TYPE = [
		'audio/mp4' => 'm4a',
		'audio/x-m4a' => 'm4a',
		'audio/ogg' => 'ogg',
		'audio/mpeg' => 'mp3',
		'video/mp4' => 'mp4',
		'video/webm' => 'webm',
		'video/ogg' => 'ogv',
		'image/jpeg' => 'jpg',
		'image/jpg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
		'image/bmp' => 'bmp',
		'image/tiff' => 'tiff',
		'image/svg+xml' => 'svg',
	];

	/**
	 * Extensions a recording may be named after when the table above knows no recording format of its
	 * mime type. Deliberately wider than the values of that table: the step exists for a format that
	 * reached the pipeline before its row did, so a list drawn from the table would never accept
	 * anything. Only audio and video formats a recording pipeline produces belong here - a preview
	 * never takes its name from the notification, and an extension of any other kind would let the
	 * recording service hand a portal user a file whose name promises something a recording never is.
	 */
	private const RECORDING_MEDIA_EXTENSIONS = [
		'm4a', 'mp3', 'ogg', 'oga', 'opus', 'weba', 'wav', 'aac', 'flac',
		'mp4', 'm4v', 'webm', 'ogv', 'mov',
	];

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

	/**
	 * [DEBUG] Consume one forced-failure attempt for the given option.
	 * Returns true while the counter is positive, decrementing it so the finalize flow
	 * self-recovers after N attempts and the option resets to 0. Disabled by default (0).
	 * Toggle: \Bitrix\Main\Config\Option::set('call', $optionName, '2').
	 */
	private static function consumeDebugErrorAttempt(string $optionName): bool
	{
		$attempts = (int)\Bitrix\Main\Config\Option::get('call', $optionName, 0);
		if ($attempts <= 0)
		{
			return false;
		}

		\Bitrix\Main\Config\Option::set('call', $optionName, (string)($attempts - 1));

		return true;
	}

	public function attachTempFile(): Result
	{
		if (self::consumeDebugErrorAttempt('debug_force_save_error_attempts'))
		{
			return (new Result())
				->setData(['debug_forced_error' => true])
				->addError(new TrackError(TrackError::SAVE_FILE_ERROR, 'Track: forced SAVE_FILE_ERROR for testing'));
		}

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

		$fileId = (int)$this->getFileId();
		$fileSize = (int)$this->getFileSize();

		if (!$this->claimDownloadedFile($tempPath, $fileId, $fileSize))
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error(
					"Track::attachTempFile: the row was pointed at another delivery while this file"
					. " was being saved, so the landing of this one is dropped. TrackId: {$this->getId()}"
				);
			}

			// The file this pass made addresses nothing: the row names the delivery that replaced
			// it, and no second field ever named this one.
			\CFile::Delete($fileId);
			$this->sysReset('FILE_ID')->sysReset('FILE_SIZE');

			return (new Result())->addError(new TrackError(
				TrackError::SAVE_FILE_ERROR,
				'The track row now points at another delivery'
			));
		}

		// The row and this object hold the same thing now, and the fields the claim wrote are not
		// written a second time by the save below {@see self::claimDownloadedFile()}.
		$this->sysSetActual('FILE_ID', $fileId);
		$this->sysSetActual('FILE_SIZE', $fileSize);
		$this->sysReset('FILE_ID')->sysReset('FILE_SIZE');
		$this->sysSetActual('TEMP_PATH', null);
		$this->sysSetActual('DOWNLOAD_URL', null);
		$this->sysSetActual('DOWNLOADED', true);

		// Whatever else this pass has learned about the row - a duration, a name, a mime type -
		// still travels the way it always did. The landing itself is already written.
		$saved = $this->save();
		if (!$saved->isSuccess())
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error(
					"Track::attachTempFile: the attached file was not saved to the track."
					. " TrackId: {$this->getId()}, Errors: " . implode('; ', $saved->getErrorMessages())
				);
			}

			return $result->addError(new TrackError(TrackError::SAVE_FILE_ERROR, 'Track row was not saved'));
		}

		// Only once the row has stopped pointing at the file: the cleanup runs inline in a console
		// process {@see \Bitrix\Call\Background\JobDispatcher}, so on a failed save the row would
		// keep TEMP_PATH of a file this pass has already deleted, and the next pass of the agent
		// would resume the download into a path that is no longer there
		// {@see \Bitrix\Call\Track\Downloader\ChunkedDownloader::download()}.
		self::scheduleTempCleanup($tempPath);

		return $result;
	}

	/**
	 * Writes the landing of a download into the row, and only while the row is still the row this
	 * pass set out to land on.
	 *
	 * One statement and not a save of this object, because the object is old by the time it gets
	 * here: the file was being written to the storage for as long as the recording is big, and no
	 * lock is held over that - the download takes none, and the two locks around it belong to the
	 * action of the restart and to the intake of a delivery, neither of which the agent takes
	 * {@see \Bitrix\Call\Track\TrackService::takeDeliveredFile()}. A new delivery of the very same
	 * recording is exactly what happens in that window, because the operator asks for one while
	 * the first download is still going: the replacement points the row at the new address
	 * {@see \Bitrix\Call\Track\TrackService::replaceLocalCopy()}, and a save of this object would
	 * then write the old file back and the new address away - the address of the replacement gone
	 * from the row, no agent left to schedule by it, and the analysis running on the recording the
	 * operator asked to be rid of, under an answer that said the restart had started.
	 *
	 * TEMP_PATH is what the row is recognised by: it names the very file this pass downloaded, a
	 * replacement empties it, and the next download of the row generates a new one. So a row that
	 * still carries it is the row of this download, and a row that does not is somebody else's.
	 *
	 * The caller answers a lost claim as the failure of the attach, which is what it is: the file
	 * of this pass never reached the row {@see self::attachTempFile()}.
	 */
	private function claimDownloadedFile(string $tempPath, int $fileId, int $fileSize): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute(
			'UPDATE ' . CallTrackTable::getTableName()
			. ' SET FILE_ID = ' . $fileId
			. ', FILE_SIZE = ' . $fileSize
			. ", TEMP_PATH = NULL, DOWNLOAD_URL = NULL, DOWNLOADED = 'Y'"
			. ' WHERE ID = ' . (int)$this->getId()
			. " AND TEMP_PATH = '" . $helper->forSql($tempPath) . "'"
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Puts the file the row holds on the Disk of the chat of the call and links it there.
	 *
	 * $replaceDiskContent asks for the third of the three things that can happen to the file of
	 * the chat Disk: it is reused as it is, it is created, or - on a new delivery of the recording
	 * that replaces the local copy of it {@see \Bitrix\Call\Track\TrackService::replaceLocalCopy()}
	 * - the file the row has held all along gets the new recording as a new version. The link of
	 * the chat points at that file and not at this row, so a file created beside it would leave the
	 * chat with two links to one recording, and the call looking like a call with no recording at
	 * all until the second one arrives.
	 */
	public function attachToDisk(bool $replaceDiskContent = false): Result
	{
		if (self::consumeDebugErrorAttempt('debug_force_disk_error_attempts'))
		{
			return (new Result())
				->setData(['debug_forced_error' => true])
				->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'Track: forced DISK_ATTACH_ERROR for testing'));
		}

		$result = new Result();

		$chatId = $this->fillCall()?->getChatId();
		if ($chatId)
		{
			$callInitiatorId = $this->getCall()->getInitiatorId();

			// Pre-existing diskFileId means this is a retry: only then can a chat link already
			// exist (from a prior attempt or the audio-record message), so only then is the
			// getByDiskFileId lookup below worth doing.
			$diskFilePreExisted = (bool)$this->getDiskFileId();

			if ($this->getDiskFileId())
			{
				$diskFileId = $this->getDiskFileId();

				if ($replaceDiskContent)
				{
					$versionResult = $this->uploadDiskVersion((int)$diskFileId, (int)$callInitiatorId);
					if (!$versionResult->isSuccess())
					{
						// The class of the answer is the one of every other failure here, so the
						// finalization takes the disk branch and the agent retries: the row keeps
						// pointing at the file of the chat, and the next pass writes the version
						// again over the very same file.
						return $result
							->setData([
								'diskFileId' => $diskFileId,
								'chatDelivered' => false,
								'chatReason' => 'disk_version_failed',
							])
							->addErrors($versionResult->getErrors())
						;
					}
				}
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
				$diskSaved = $this->setDiskFileId($diskFileId)->save();
				if (!$diskSaved->isSuccess())
				{
					// The file is on the chat Disk and the row does not know it. In memory this
					// object looks finished, and the pipeline asks about the portal by the row:
					// left as a success it would take the record off the retries with nothing to
					// find it by {@see \Bitrix\Call\Track\TrackService::isStoredOnPortal()}. The
					// class of the answer is the one of this method, so the finalization takes the
					// disk branch it takes for every other failure here.
					if (CallAISettings::isLoggingEnable())
					{
						Logger::getInstance()->error(
							"Track::attachToDisk: the disk file id was not saved to the track."
							. " TrackId: {$this->getId()}, DiskFileId: {$diskFileId}"
							. ", Errors: " . implode('; ', $diskSaved->getErrorMessages())
						);
					}

					return $result
						->setData([
							'diskFileId' => $diskFileId,
							'chatDelivered' => false,
							'chatReason' => 'disk_file_id_save_failed',
						])
						->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'Disk file id was not saved'))
					;
				}

				// For video records the chat "recording ready" message attaches this file and owns
				// the media entry. Creating a link here would duplicate it in chat "Media & files".
				// Other disk types have no such message, so the explicit link is their only surface.
				if ($this->getType() === self::TYPE_VIDEO_RECORD)
				{
					$result->setData([
						'diskFileId' => $diskFileId,
						'chatDelivered' => true,
						'chatReason' => 'message_owned',
					]);

					return $result;
				}

				// The disk file id may have pre-existed (retry path), in which case im was not
				// loaded above — ensure it before touching im V2 link classes.
				if (!Loader::includeModule('im'))
				{
					return $result
						->setData(['diskFileId' => $diskFileId, 'chatDelivered' => false, 'chatReason' => 'im_unavailable'])
						->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'Can not create chat file link: im module unavailable'));
				}

				// Idempotency / no duplication: on a retry a chat file link for this disk file may
				// already exist in THIS chat — created by a previous finalize attempt or by the
				// audio-record chat message (which attaches the same file and auto-creates a link).
				// Creating a second one would duplicate it in chat "Media & files", so reuse it.
				// Scope by CHAT_ID: the same disk file can be linked in other chats, and that must
				// not be mistaken for delivery to the current chat. On the first pass the diskFileId
				// was just created here, so the lookup is skipped.
				$existingLink = $diskFilePreExisted
					? \Bitrix\Im\Model\LinkFileTable::query()
						->setSelect(['ID'])
						->where('DISK_FILE_ID', $diskFileId)
						->where('CHAT_ID', $chatId)
						->setLimit(1)
						->fetchObject()
					: null
				;
				if ($existingLink)
				{
					return $result->setData([
						'diskFileId' => $diskFileId,
						'chatDelivered' => true,
						'chatReason' => 'link_exists',
					]);
				}

				$type = match (true)
				{
					$this->getType() == self::TYPE_RECORD => \Bitrix\Im\V2\Link\File\FileItem::AUDIO_SUBTYPE,
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
				$linkSaved = $link->save()->isSuccess();
				if ($linkSaved)
				{
					\Bitrix\Im\V2\Link\Push::getInstance()
						->sendFull($link, 'fileAdd', ['CHAT_ID' => $link->getChatId()]);

					$result->setData([
						'diskFileId' => $diskFileId,
						'chatDelivered' => true,
						'chatReason' => null,
					]);
				}
				else
				{
					// The file is on disk but the chat link failed to save — report it as a disk
					// error so finalizeDownload fails and the download agent retries delivery.
					// The retry is idempotent: getByDiskFileId above prevents a duplicate link.
					$result
						->setData(['diskFileId' => $diskFileId, 'chatDelivered' => false, 'chatReason' => 'link_save_failed'])
						->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'Chat file link save failed'));
				}
			}
			else
			{
				// Upload returned an empty disk file id — report as a disk error so finalizeDownload
				// hits the explicit disk-failure branch (fireTrackErrorEvent + finalize_disk_failed),
				// consistent with the module-unavailable case above.
				return $result
					->setData(['chatDelivered' => false, 'chatReason' => 'disk_upload_failed'])
					->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'Disk upload returned empty file id'));
			}
		}
		else
		{
			// A disk-requiring record without a chat is an anomaly: report it as a disk error so
			// finalizeDownload takes the explicit disk-failure branch instead of a silent success
			// that the agent would keep retrying while emitting duplicate telemetry.
			return $result
				->setData(['chatDelivered' => false, 'chatReason' => 'no_chat_id'])
				->addError(new TrackError(TrackError::DISK_ATTACH_ERROR, 'No chat ID for disk attachment'));
		}

		return $result;
	}

	/**
	 * Writes the file the row holds now as a new version of the file of the chat Disk the row has
	 * pointed at all along {@see self::attachToDisk()}.
	 *
	 * The version takes the very file of the row and does not copy its bytes, the way the creation
	 * of the disk file takes them {@see \CIMDisk::UploadFileFromMain()}. The join with the previous
	 * version is switched off on purpose: the recording the chat served until now stays a version
	 * of its own, and the file of it stays owned by that version instead of being deleted by the
	 * join.
	 */
	private function uploadDiskVersion(int $diskFileId, int $authorId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(
				new TrackError(TrackError::DISK_ATTACH_ERROR, 'Can not update file on chat disk')
			);
		}

		$diskFile = \Bitrix\Disk\File::getById($diskFileId);
		if (!$diskFile instanceof \Bitrix\Disk\File)
		{
			return $result->addError(
				new TrackError(TrackError::DISK_ATTACH_ERROR, "Disk file #{$diskFileId} of the track is gone")
			);
		}

		$fileArray = \CFile::GetFileArray($this->getFileId());
		if (empty($fileArray))
		{
			return $result->addError(
				new TrackError(TrackError::DISK_ATTACH_ERROR, 'The new file of the track is gone')
			);
		}

		if (!$diskFile->addVersion($fileArray, $authorId, true))
		{
			$reasons = implode(
				'; ',
				array_map(static fn($error): string => (string)$error->getMessage(), $diskFile->getErrors())
			);

			return $result->addError(new TrackError(
				TrackError::DISK_ATTACH_ERROR,
				'Disk file version was not created' . ($reasons === '' ? '' : ': ' . $reasons)
			));
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

	public function getDownloadLink(): string
	{
		if ($this->getDiskFileId() && Loader::includeModule('disk'))
		{
			$diskFile = \Bitrix\Disk\File::getById($this->getDiskFileId());
			if ($diskFile)
			{
				$urlManager = \Bitrix\Main\Engine\UrlManager::getInstance();
				$uri = $urlManager->create('disk.api.file.download', [
					'humanRE' => 1,
					'fileId' => $diskFile->getId(),
					'exact' => 'N',
					'fileName' => $diskFile->getName(),
				], true);

				return $uri->getUri();
			}
		}

		return $this->getUrl(true, true);
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

	/**
	 * Builds the user-visible file name. Receivers set the name from the ready notification before calling
	 * this, so a track type without a name template here keeps the notification name as the final one.
	 */
	public function generateFilename(): self
	{
		$callId = $this->getCallId();
		$externalId = $this->getExternalTrackId();

		if ($this->getType() == self::TYPE_TRACK_PACK)
		{
			$this->setFileName("track-pack-{$callId}-{$externalId}.zip");
		}
		elseif (in_array($this->getType(), [self::TYPE_RECORD, self::TYPE_VIDEO_RECORD], true))
		{
			$nameBase = $this->getLocalizedFileNameBase() ?: "composed-{$callId}";

			$this->setFileName($nameBase . '.' . $this->resolveRecordFileExtension());
		}
		elseif ($this->getType() == self::TYPE_VIDEO_PREVIEW)
		{
			$localizedName = $this->getLocalizedFileNameBase();
			$nameBase = $localizedName ? 'preview_' . $localizedName : "preview-{$callId}";
			$extension = $this->getImageFileExtensionFromMimeType() ?: 'jpg';

			$this->setFileName("{$nameBase}.{$extension}");
		}
		elseif (!$this->getFileName())
		{
			$this->setFileName("record-{$externalId}");
		}

		return $this;
	}

	private function getLocalizedFileNameBase(): string
	{
		return (string)Loc::getMessage('CALL_TRACK_RECORD_FILE_NAME', [
			'#CALL_ID#' => $this->getCallId(),
			'#CALL_START#' => (new DateTime())->format('Y-m-d'),
		]);
	}

	/**
	 * Extension of a recording: mime type first, then the extension of the name sent with the ready
	 * notification, then the legacy fallback. The fallback means the format is not in the table yet.
	 *
	 * The name is chosen by the recording service, so the extension it carries is taken only when it
	 * names a media format the pipeline may produce.
	 */
	private function resolveRecordFileExtension(): string
	{
		$extension = $this->getRecordingFileExtensionFromMimeType();
		if ($extension !== null)
		{
			return $extension;
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();
		$callId = $this->getCallId();
		$mimeType = (string)$this->getFileMimeType();
		$fileName = (string)$this->getFileName();
		$loggedMimeType = self::withoutLineBreaks($mimeType);

		$log && $logger->warning(
			'Track::resolveRecordFileExtension: unknown recording mime type.'
			. " CallId: {$callId}, MimeType: {$loggedMimeType}",
		);

		$extensionFromName = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (in_array($extensionFromName, self::RECORDING_MEDIA_EXTENSIONS, true))
		{
			return $extensionFromName;
		}

		$loggedFileName = self::withoutLineBreaks($fileName);

		$log && $logger->warning(
			'Track::resolveRecordFileExtension: recording extension fallback applied.'
			. " CallId: {$callId}, MimeType: {$loggedMimeType}, FileName: {$loggedFileName}",
		);

		return self::FALLBACK_FILE_EXTENSION;
	}

	/**
	 * A recording is sound or moving picture, while the shared table also knows the image types of
	 * a preview: only an audio or video type may name a recording.
	 */
	private function getRecordingFileExtensionFromMimeType(): ?string
	{
		$mimeType = (string)$this->getFileMimeType();
		if (!str_starts_with($mimeType, 'audio/') && !str_starts_with($mimeType, 'video/'))
		{
			return null;
		}

		return $this->getFileExtensionFromMimeType();
	}

	/**
	 * A preview is always an image, while the shared table also knows the audio and video types of
	 * the recording pipeline: only an image type may name a preview.
	 */
	private function getImageFileExtensionFromMimeType(): ?string
	{
		if (!str_starts_with((string)$this->getFileMimeType(), 'image/'))
		{
			return null;
		}

		return $this->getFileExtensionFromMimeType();
	}

	/**
	 * Get file extension based on MIME type
	 *
	 * @return string|null
	 */
	private function getFileExtensionFromMimeType(): ?string
	{
		$mimeType = $this->getFileMimeType();
		if (!$mimeType)
		{
			return null;
		}

		return self::EXTENSION_BY_MIME_TYPE[$mimeType] ?? null;
	}

	private static function withoutLineBreaks(string $value): string
	{
		return str_replace(["\r", "\n"], ' ', $value);
	}

	/**
	 * Check if directory is empty
	 */
	private static function isDirectoryEmpty(\Bitrix\Main\IO\Directory $dir): bool
	{
		return empty($dir->getChildren());
	}

	/**
	 * Schedule temp file cleanup via {@see \Bitrix\Call\Background\JobDispatcher}.
	 *
	 * The caller is the code of the agent {@see self::attachTempFile()}, so the cleanup was
	 * scheduled exactly where a deferred job is dropped: the temp files of a clone installation
	 * piled up and nothing ever came back for them. The operation is one file and one directory,
	 * cheap enough to do inline in a process that has no response to protect.
	 */
	public static function scheduleTempCleanup(string $tempPath): void
	{
		JobDispatcher::getInstance()->enqueue(
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

	public static function getTracksForCall(int $callId, string $type): Track\TrackCollection
	{
		return CallTrackTable::getList([
			'select' => ['*'],
			'filter' => ['=CALL_ID' => $callId, '=TYPE' => $type],
			'order' => ['ID' => 'ASC'],
		])->fetchCollection();
	}
}
