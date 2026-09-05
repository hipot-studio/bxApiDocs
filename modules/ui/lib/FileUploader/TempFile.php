<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Loader;
use Bitrix\Main\IO;
use Bitrix\Main\Result;

final class TempFile extends EO_TempFile
{
	public const STRATEGY_PARALLEL = 'parallel';
	public const STRATEGY_PRESIGNED = 'presigned';

	private ?\CCloudStorageBucket $bucket = null;

	public static function create(Chunk $chunk, UploaderController $controller, ?string $strategy = null): Result
	{
		$result = new Result();
		$file = $chunk->getFile();
		if (!$file->isExists())
		{
			return $result->addError(new UploaderError(UploaderError::CHUNK_NOT_FOUND));
		}

		if (mb_strpos($file->getPhysicalPath(), \CTempFile::getAbsoluteRoot()) !== 0)
		{
			// A chunk file could be saved in any folder.
			// Copy it to the temporary directory. We need to normalize the absolute path.
			$tempFilePath = self::generateLocalTempFile();
			if (!copy($chunk->getFile()->getPhysicalPath(), $tempFilePath))
			{
				return $result->addError(new UploaderError(UploaderError::CHUNK_COPY_FAILED));
			}

			$newFile = new IO\File($tempFilePath);
			if (!$newFile->isExists())
			{
				return $result->addError(new UploaderError(UploaderError::CHUNK_COPY_FAILED));
			}

			$chunk->setFile($newFile);
		}

		// Parallel strategy only makes sense for multipart uploads. For a single chunk
		// we always fall back to sequential — sparse files / cloud multipart sessions
		// are not justified in that case.
		$isParallel = ($strategy === self::STRATEGY_PARALLEL && !$chunk->isOnlyOne());

		if ($chunk->isOnlyOne())
		{
			// Cloud and local files are processed by CFile::SaveFile.
			$tempFile = self::createTempFile($chunk, $controller);
		}
		elseif ($isParallel)
		{
			$createResult = self::createParallel($chunk, $controller);
			if (!$createResult->isSuccess())
			{
				return $result->addErrors($createResult->getErrors());
			}

			$tempFile = $createResult->getData()['tempFile'];
		}
		else
		{
			// Multipart upload (sequential)
			$bucket = self::findBucketForFile($chunk, $controller);
			if ($bucket)
			{
				// cloud file
				$tempFile = self::createTempFile($chunk, $controller, $bucket);
				$appendResult = $tempFile->appendToCloud($chunk);
				if (!$appendResult->isSuccess())
				{
					$chunk->getFile()->delete();
					$tempFile->delete();

					return $result->addErrors($appendResult->getErrors());
				}
			}
			else
			{
				// local file
				$localTempDir = self::generateLocalTempDir();
				if (!$file->rename($localTempDir))
				{
					return $result->addError(new UploaderError(UploaderError::FILE_MOVE_FAILED));
				}

				$tempFile = self::createTempFile($chunk, $controller);
			}
		}

		$result->setData(['tempFile' => $tempFile]);

		return $result;
	}

	/**
	 * Initiates a parallel upload session. Prepares storage for out-of-order part
	 * writes: a sparse file (local) or a MultipartUpload session (cloud). Writes
	 * the first part and records it in b_ui_file_uploader_temp_file_part.
	 */
	private static function createParallel(Chunk $chunk, UploaderController $controller): Result
	{
		$result = new Result();
		$bucket = self::findBucketForFile($chunk, $controller);

		if ($bucket)
		{
			// Cloud: ensure part size is not smaller than required by the protocol.
			$minUploadSize = $bucket->getService()->getMinUploadPartSize();
			if ($chunk->getSize() < $minUploadSize && !$chunk->isLast())
			{
				return $result->addError(new UploaderError(
					UploaderError::CLOUD_INVALID_CHUNK_SIZE,
					[
						'chunkSize' => $chunk->getSize(),
						'minUploadSize' => $minUploadSize,
						'postMaxSize' => \CUtil::unformat(ini_get('post_max_size')),
						'uploadMaxFileSize' => \CUtil::unformat(ini_get('upload_max_filesize')),
					]
				));
			}

			$tempFile = self::createTempFile($chunk, $controller, $bucket, self::STRATEGY_PARALLEL);

			$initResult = $tempFile->initParallelCloud($chunk->getFileSize(), $chunk->getType());
			if (!$initResult->isSuccess())
			{
				$tempFile->delete();
				return $result->addErrors($initResult->getErrors());
			}

			$writeResult = $tempFile->writePartCloud($chunk, 1);
			if (!$writeResult->isSuccess())
			{
				$tempFile->delete();
				return $result->addErrors($writeResult->getErrors());
			}
		}
		else
		{
			$sparseFilePath = self::generateLocalTempDir();
			$tempFile = self::createTempFile($chunk, $controller, null, self::STRATEGY_PARALLEL, $sparseFilePath);

			// No pre-allocation: writePartLocal opens the file with mode 'c+', which
			// creates it on the first call. Subsequent writes at arbitrary offsets are
			// extended automatically (POSIX sparse extension).
			$writeResult = $tempFile->writePartLocal($chunk, 1);
			if (!$writeResult->isSuccess())
			{
				$tempFile->delete();

				return $result->addErrors($writeResult->getErrors());
			}

			$chunk->getFile()->delete();
		}

		$partAddResult = TempFilePartTable::add([
			'TEMP_FILE_ID' => $tempFile->getId(),
			'PART_NO' => 1,
		]);

		if (!$partAddResult->isSuccess())
		{
			$tempFile->delete();
			return $result->addErrors($partAddResult->getErrors());
		}

		return $result->setData(['tempFile' => $tempFile]);
	}

	/**
	 * Initiates a presigned multipart session. The first byte never reaches PHP —
	 * the browser will PUT each part directly into S3 via presigned URLs.
	 * Here we resolve the bucket, create the TempFile row and ask the cloud
	 * for an UploadId.
	 *
	 * @param FileData $fileData File descriptor (name/contentType/size; validated upstream).
	 * @param UploaderController $controller Owner controller.
	 * @param int|null $requestedPartSize Optional client-pinned part size; clamped to a safe range.
	 * @return Result Result with 'tempFile' => TempFile on success,
	 *  PRESIGNED_UNSUPPORTED when no presigned-capable bucket matches the file.
	 */
	public static function createPresigned(
		FileData $fileData,
		UploaderController $controller,
		?int $requestedPartSize = null,
	): Result
	{
		$result = new Result();

		// Locate a writable, presigned-capable bucket. findBucketForFile() with a
		// Chunk aligns with the sequential/parallel paths; for presigned we have
		// no chunk yet — call CCloudStorage directly with the same input shape.
		if (!Loader::includeModule('clouds'))
		{
			return $result->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED));
		}

		$bucket = \CCloudStorage::findBucketForFile(
			[
				'FILE_SIZE' => $fileData->getSize(),
				'MODULE_ID' => $controller->getCommitOptions()->getModuleId(),
			],
			$fileData->getName(),
		);
		if (!$bucket || !$bucket->init() || !$bucket->supportsPresignedUrls())
		{
			return $result->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED));
		}

		// Pick a part size that satisfies S3 (>= service minUploadPartSize) and
		// our own bounds (<= chunkMaxSize). The client may pin a specific size via
		// $requestedPartSize (useful for tuning around CDN/proxy body limits); we
		// clamp it to the safe range anyway.
		$minPartSize = $bucket->getService()->getMinUploadPartSize();
		$partSize = (
			$requestedPartSize !== null && $requestedPartSize > 0
				? $requestedPartSize
				: $minPartSize
		);

		$partSize = max($minPartSize, $partSize);
		$partSize = min($partSize, 100 * 1024 * 1024);
		$partCount = (int)ceil($fileData->getSize() / max($partSize, 1));

		$tempFile = new TempFile();
		$tempFile->setFilename($fileData->getName());
		$tempFile->setMimetype($fileData->getContentType());
		$tempFile->setSize($fileData->getSize());
		$tempFile->setWidth($fileData->getWidth());
		$tempFile->setHeight($fileData->getHeight());
		$tempFile->setReceivedSize(0);
		$tempFile->setModuleId($controller->getModuleId());
		$tempFile->setController($controller->getName());
		$tempFile->setControllerOptions($controller->getOptions());
		$tempFile->setStrategy(self::STRATEGY_PRESIGNED);
		$tempFile->setPartSize($partSize);
		$tempFile->setPartCount($partCount);
		$tempFile->setCloud(true);
		$tempFile->setBucketId($bucket->ID);
		$tempFile->setPath(self::generateCloudTempDir($bucket));
		$tempFile->save();

		// Initiate the multipart session in S3. Parts will be PUT directly by
		// the client; we only need the UploadId for presigning and later finish.
		$cloudUpload = new \CCloudStorageUpload($tempFile->getPath());
		if (!$cloudUpload->isStarted() && !$cloudUpload->start($bucket->ID, $fileData->getSize(), $fileData->getContentType()))
		{
			$tempFile->delete();

			return $result->addError(new UploaderError(UploaderError::PRESIGNED_INIT_FAILED));
		}

		$cloudState = $cloudUpload->GetArray();
		$uploadInfo = is_array($cloudState) && isset($cloudState['NEXT_STEP'])
			? unserialize($cloudState['NEXT_STEP'], ['allowed_classes' => false])
			: null;
		if (!is_array($uploadInfo) || empty($uploadInfo['UploadId']))
		{
			$tempFile->delete();

			return $result->addError(new UploaderError(UploaderError::PRESIGNED_INIT_FAILED));
		}

		$tempFile->setUploadId($uploadInfo['UploadId']);
		$tempFile->save();

		return $result->setData(['tempFile' => $tempFile]);
	}

	protected static function createTempFile(
		Chunk $chunk,
		UploaderController $controller,
		$bucket = null,
		?string $strategy = null,
		?string $explicitLocalPath = null,
	): TempFile
	{
		$tempFile = new TempFile();
		$tempFile->setFilename($chunk->getName());
		$tempFile->setMimetype($chunk->getType());
		$tempFile->setSize($chunk->getFileSize());
		$tempFile->setReceivedSize($chunk->getSize());
		$tempFile->setWidth($chunk->getWidth());
		$tempFile->setHeight($chunk->getHeight());
		$tempFile->setModuleId($controller->getModuleId());
		$tempFile->setController($controller->getName());
		$tempFile->setControllerOptions($controller->getOptions());

		if ($bucket)
		{
			$path = self::generateCloudTempDir($bucket);
		}
		elseif ($explicitLocalPath !== null)
		{
			$tempRoot = \CTempFile::getAbsoluteRoot();
			$path = mb_substr($explicitLocalPath, mb_strlen($tempRoot));
		}
		else
		{
			$path = $chunk->getFile()->getPhysicalPath();
			$tempRoot = \CTempFile::getAbsoluteRoot();
			$path = mb_substr($path, mb_strlen($tempRoot));
		}

		$tempFile->setPath($path);

		if ($bucket)
		{
			$tempFile->setCloud(true);
			$tempFile->setBucketId($bucket->ID);
		}

		if ($strategy === self::STRATEGY_PARALLEL)
		{
			$partSize = $chunk->getSize();
			$tempFile->setStrategy(self::STRATEGY_PARALLEL);
			$tempFile->setPartSize($partSize);
			$tempFile->setPartCount((int)ceil($chunk->getFileSize() / max($partSize, 1)));
		}

		$tempFile->save();

		return $tempFile;
	}

	public function getControllerOptions(): array
	{
		$value = parent::getControllerOptions();

		return is_array($value) ? $value : [];
	}

	public function append(Chunk $chunk): Result
	{
		$result = new Result();

		if ($chunk->getEndRange() < $this->getReceivedSize())
		{
			// We already have this part of the file
			return $result;
		}

		if ($this->getReceivedSize() !== $chunk->getStartRange())
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_CHUNK_OFFSET));
		}

		if ($this->getReceivedSize() + $chunk->getSize() > $this->getSize())
		{
			return $result->addError(new UploaderError(UploaderError::CHUNK_TOO_BIG));
		}

		$result = $this->isCloud() ? $this->appendToCloud($chunk) : $this->appendToFile($chunk);
		if ($result->isSuccess())
		{
			$this->increaseReceivedSize($chunk->getSize());
		}

		// Remove a temporary chunk file immediately
		$chunk->getFile()->delete();

		return $result;
	}

	public function commit(CommitOptions $commitOptions): Result
	{
		$fileAbsolutePath = $this->getAbsolutePath();

		$fileId = \CFile::saveFile(
			[
				'name' => $this->getFilename(),
				'tmp_name' => $fileAbsolutePath,
				'type' => $this->getMimetype(),
				'MODULE_ID' => $commitOptions->getModuleId(),
				'width' => $this->getWidth(),
				'height' => $this->getHeight(),
				'size' => $this->getSize(),
			],
			$commitOptions->getSavePath(),
			$commitOptions->isForceRandom(),
			$commitOptions->isSkipExtension(),
			$commitOptions->getAddDirectory()
		);

		$result = new Result();
		if (!$fileId)
		{
			$this->delete();

			return $result->addError(new UploaderError(UploaderError::SAVE_FILE_FAILED));
		}

		$this->setFileId($fileId);
		$this->setUploaded(true);
		$this->save();
		$this->fillFile();

		$this->removeActualTempFile();

		return $result;
	}

	public function isCloud(): bool
	{
		return $this->getCloud() && $this->getBucketId() > 0;
	}

	public function isParallel(): bool
	{
		return $this->getStrategy() === self::STRATEGY_PARALLEL;
	}

	/**
	 * Starts a multipart upload session in the cloud without writing any data.
	 * Parts can then be sent in arbitrary order via writePartCloud().
	 */
	public function initParallelCloud(int $totalSize, string $mimeType): Result
	{
		$result = new Result();
		$bucket = $this->getBucket();
		if (!$bucket)
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_EMPTY_BUCKET));
		}

		$cloudUpload = new \CCloudStorageUpload($this->getPath());
		if ($cloudUpload->isStarted())
		{
			return $result;
		}

		if (!$cloudUpload->start($bucket->ID, $totalSize, $mimeType))
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_START_UPLOAD_FAILED));
		}

		return $result;
	}

	/**
	 * Writes a single part at a fixed offset into the local sparse file.
	 * The first call creates the file (mode 'c+'); subsequent calls at higher offsets
	 * grow it via POSIX sparse extension — no pre-allocation is required.
	 *
	 * partNo is 1-based — aligned with S3 PartNumber and CCloudStorageUpload::Part.
	 * The write offset is derived solely from partNo and the server-fixed PART_SIZE.
	 * The client-provided Content-Range (startRange) is NOT trusted here: relying on
	 * it would let a client place a part at an arbitrary offset and silently corrupt
	 * the assembled file while RECEIVED_SIZE still reaches SIZE.
	 *
	 * POSIX guarantees safety of concurrent writes through different file
	 * descriptors into non-overlapping regions. Filesystem requirement: sparse
	 * file support (ext4/xfs/apfs — yes; NFS/SMB may misbehave).
	 *
	 * Uses IO\File for open/seek/close; fwrite has no IO wrapper and is called on
	 * the raw file pointer returned by File::open(). IO\File::seek() also handles
	 * offsets above PHP_INT_MAX correctly on 32-bit builds.
	 */
	public function writePartLocal(Chunk $chunk, int $partNo): Result
	{
		$result = new Result();
		$file = new IO\File($this->getAbsoluteLocalPath());
		$offset = ($partNo - 1) * (int)$this->getPartSize();

		try
		{
			$fp = $file->open('c+');
		}
		catch (\Throwable $e)
		{
			return $result->addError(new UploaderError(
				UploaderError::PART_WRITE_FAILED,
				['partNo' => $partNo]
			));
		}

		try
		{
			if ($file->seek($offset) === -1)
			{
				return $result->addError(new UploaderError(
					UploaderError::PART_WRITE_FAILED,
					['partNo' => $partNo]
				));
			}

			$content = $chunk->getFile()->getContents();
			$expected = strlen($content);
			$written = @fwrite($fp, $content);

			if ($written === false || $written !== $expected)
			{
				return $result->addError(new UploaderError(
					UploaderError::PART_WRITE_FAILED,
					['partNo' => $partNo]
				));
			}
		}
		finally
		{
			self::safeClose($file);
		}

		return $result;
	}

	private static function safeClose(IO\File $file): void
	{
		try
		{
			$file->close();
		}
		catch (\Throwable $e)
		{
			// Already closed or never opened — nothing to do.
		}
	}

	/**
	 * Uploads a single part to the cloud with an arbitrary PartNumber. partNo is
	 * 1-based — it maps directly to S3 PartNumber without extra translation.
	 *
	 * The race on the shared NEXT_STEP (array of ETags) is protected by a lock
	 * on the CCloudStorageUpload::Part side — our code does not handle it.
	 */
	public function writePartCloud(Chunk $chunk, int $partNo): Result
	{
		$result = new Result();
		$bucket = $this->getBucket();
		if (!$bucket)
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_EMPTY_BUCKET));
		}

		$cloudUpload = new \CCloudStorageUpload($this->getPath());
		if (!$cloudUpload->isStarted())
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_START_UPLOAD_FAILED));
		}

		$content = $chunk->getFile()->isExists() ? $chunk->getFile()->getContents() : false;
		if ($content === false)
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_GET_CONTENTS_FAILED));
		}

		// CCloudStorageUpload::Part expects a 0-based part index (the sequential path
		// calls it as count($parts) = 0,1,2,…; internally it uploads to S3
		// PartNumber = index + 1). Our partNo is 1-based, so convert — otherwise
		// parts land at S3 PartNumber 2..N+1 (no part #1), and strict S3 providers
		// reject CompleteMultipartUpload with "parts not in ascending order".
		if (!$cloudUpload->Part($content, $partNo - 1, $bucket))
		{
			return $result->addError(new UploaderError(
				UploaderError::CLOUD_UPLOAD_PART_FAILED,
				['partNo' => $partNo]
			));
		}

		return $result;
	}

	public function makePersistent(): void
	{
		$this->customData->set('deleteBFile', false);
		$this->delete();
	}

	public function deleteContent($deleteBFile = true): void
	{
		$this->removeActualTempFile();
		if ($deleteBFile)
		{
			\CFile::delete($this->getFileId());
		}
	}

	private function removeActualTempFile(): bool
	{
		if ($this->getDeleted())
		{
			return true;
		}

		$success = false;
		if ($this->isCloud())
		{
			$bucket = $this->getBucket();
			if ($bucket)
			{
				$success = $bucket->deleteFile($this->getPath());
			}
		}
		else
		{
			$success = IO\File::deleteFile($this->getAbsolutePath());
		}

		if ($success)
		{
			$this->setDeleted(true);
			$this->save();
		}

		return $success;
	}

	private function getAbsoluteCloudPath(): ?string
	{
		$bucket = $this->getBucket();
		if (!$bucket)
		{
			return null;
		}

		return $bucket->getFileSRC($this->getPath());
	}

	private function getAbsoluteLocalPath(): string
	{
		return \CTempFile::getAbsoluteRoot() . $this->getPath();
	}

	private function getAbsolutePath(): ?string
	{
		if ($this->isCloud())
		{
			return $this->getAbsoluteCloudPath();
		}

		return $this->getAbsoluteLocalPath();
	}

	private function appendToFile(Chunk $chunk): Result
	{
		$result = new Result();
		$file = new IO\File($this->getAbsoluteLocalPath());

		if ($chunk->isFirst() || !$file->isExists())
		{
			return $result->addError(new UploaderError(UploaderError::FILE_APPEND_NOT_FOUND));
		}

		if ($chunk->getEndRange() < $file->getSize())
		{
			// We already have this part of the file
			return $result;
		}

		if (!$chunk->getFile()->isExists())
		{
			return $result->addError(new UploaderError(UploaderError::CHUNK_APPEND_NOT_FOUND));
		}

		if ($file->putContents($chunk->getFile()->getContents(), IO\File::APPEND) === false)
		{
			return $result->addError(new UploaderError(UploaderError::CHUNK_APPEND_FAILED));
		}

		return $result;
	}

	private function appendToCloud(Chunk $chunk): Result
	{
		$result = new Result();
		$bucket = $this->getBucket();
		if (!$bucket)
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_EMPTY_BUCKET));
		}

		$minUploadSize = $bucket->getService()->getMinUploadPartSize();
		if ($chunk->getSize() < $minUploadSize && !$chunk->isLast())
		{
			$postMaxSize = \CUtil::unformat(ini_get('post_max_size'));
			$uploadMaxFileSize = \CUtil::unformat(ini_get('upload_max_filesize'));

			return $result->addError(
				new UploaderError(
					UploaderError::CLOUD_INVALID_CHUNK_SIZE,
					[
						'chunkSize' => $chunk->getSize(),
						'minUploadSize' => $minUploadSize,
						'postMaxSize' => $postMaxSize,
						'uploadMaxFileSize' => $uploadMaxFileSize,
					]
				)
			);
		}

		$cloudUpload = new \CCloudStorageUpload($this->getPath());
		if (!$cloudUpload->isStarted() && !$cloudUpload->start($bucket->ID, $chunk->getFileSize(), $chunk->getType()))
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_START_UPLOAD_FAILED));
		}

		if ($cloudUpload->getPos() === doubleval($chunk->getEndRange() + 1))
		{
			// We already have this part of the file.
			if ($chunk->isLast() && !$cloudUpload->finish())
			{
				return $result->addError(new UploaderError(UploaderError::CLOUD_FINISH_UPLOAD_FAILED));
			}

			return $result;
		}

		$fileContent = $chunk->getFile()->isExists() ? $chunk->getFile()->getContents() : false;
		if ($fileContent === false)
		{
			return $result->addError(new UploaderError(UploaderError::CLOUD_GET_CONTENTS_FAILED));
		}

		$fails = 0;
		$success = false;
		while ($cloudUpload->hasRetries())
		{
			if ($cloudUpload->next($fileContent))
			{
				$success = true;
				break;
			}

			$fails++;
		}

		if (!$success)
		{
			// TODO: CCloudStorageUpload::CleanUp
			return $result->addError(new UploaderError(UploaderError::CLOUD_UPLOAD_FAILED, ['fails' => $fails]));
		}

		if ($chunk->isLast() && !$cloudUpload->finish())
		{
			// TODO: CCloudStorageUpload::CleanUp
			return $result->addError(new UploaderError(UploaderError::CLOUD_FINISH_UPLOAD_FAILED));
		}

		return $result;
	}

	private function increaseReceivedSize(int $bytes): void
	{
		$receivedSize = $this->getReceivedSize();
		$this->setReceivedSize($receivedSize + $bytes);
		$this->save();
	}

	private static function findBucketForFile(Chunk $chunk, UploaderController $controller): ?\CCloudStorageBucket
	{
		if (!Loader::includeModule('clouds'))
		{
			return null;
		}

		$bucket = \CCloudStorage::findBucketForFile(
			[
				'FILE_SIZE' => $chunk->getFileSize(),
				'MODULE_ID' => $controller->getCommitOptions()->getModuleId(),
			],
			$chunk->getName()
		);

		if (!$bucket || !$bucket->init())
		{
			return null;
		}

		return $bucket;
	}

	public static function generateLocalTempDir(int $hoursToKeepFile = 12): string
	{
		// The returned path is persisted (TempFile::setPath) and reused across chunk requests,
		// so we do not need a deterministic $subdir here. Passing one would only make
		// CTempFile scan every hour bucket in the keep window to re-find a directory.
		$directory = \CTempFile::getDirectoryName($hoursToKeepFile);

		if (!IO\Directory::isDirectoryExists($directory))
		{
			IO\Directory::createDirectory($directory);
		}

		$tempName = md5(mt_rand() . mt_rand());

		return $directory . $tempName;
	}

	public static function generateLocalTempFile(): string
	{
		$tmpFilePath = \CTempFile::getFileName('file-uploader' . uniqid(md5(mt_rand() . mt_rand()), true));
		$directory = IO\Path::getDirectory($tmpFilePath);
		if (!IO\Directory::isDirectoryExists($directory))
		{
			IO\Directory::createDirectory($directory);
		}

		return $tmpFilePath;
	}

	public static function generateCloudTempDir(\CCloudStorageBucket $bucket, int $hoursToKeepFile = 12): string
	{
		// The returned path is persisted (TempFile::setPath) and reused across chunk requests,
		// so we do not need a deterministic $subdir here. Passing one would only make
		// CCloudTempFile probe every hour bucket via ListFiles() (a network call per hour).
		$directory = \CCloudTempFile::getDirectoryName($bucket, $hoursToKeepFile);

		$tempName = md5(mt_rand() . mt_rand());

		return $directory . $tempName;
	}

	private function getBucket(): ?\CCloudStorageBucket
	{
		if ($this->bucket !== null)
		{
			return $this->bucket;
		}

		if (!$this->getBucketId() || !Loader::includeModule('clouds'))
		{
			return null;
		}

		$bucket = new \CCloudStorageBucket($this->getBucketId());
		if ($bucket->init())
		{
			$this->bucket = $bucket;
		}

		return $this->bucket;
	}
}
