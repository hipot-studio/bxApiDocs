<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Objectify\State;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\UI\FileUploader\Contracts\CustomFingerprint;
use Bitrix\UI\FileUploader\Contracts\CustomLoad;
use Bitrix\UI\FileUploader\Contracts\CustomRemove;

class Uploader
{
	protected UploaderController $controller;

	public function __construct(UploaderController $controller)
	{
		$this->controller = $controller;
	}

	public function getController(): UploaderController
	{
		return $this->controller;
	}

	public function upload(Chunk $chunk, ?string $token = null, ?string $strategy = null): UploadResult
	{
		$controller = $this->getController();
		$uploadResult = new UploadResult();
		if ($chunk->isFirst())
		{
			// Common file validation (uses in CFile::SaveFile)
			$error = $this->checkFile($chunk->getName(), $chunk->getFileSize(), $chunk->getType());
			if ($error !== '')
			{
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError('CHECK_FILE_FAILED', $error)),
					$controller
				);
			}

			// Controller Validation
			$configurationValidator = new ConfigurationValidator($controller->getConfiguration());
			$validationResult = $configurationValidator->validateChunk($chunk);
			if (!$validationResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult->addErrors($validationResult->getErrors()), $controller);
			}

			['width' => $width, 'height' => $height] = $validationResult->getData();
			$chunk->setWidth((int)$width);
			$chunk->setHeight((int)$height);

			$uploadRequest = new UploadRequest($chunk->getName(), $chunk->getType(), $chunk->getSize());
			$uploadRequest->setWidth($chunk->getWidth());
			$uploadRequest->setHeight($chunk->getHeight());

			// Temporary call for compatibility
			// $canUploadResult = $controller->canUpload($uploadRequest);
			$canUploadResult = call_user_func([$controller, 'canUpload'], $uploadRequest);
			if (($canUploadResult instanceof CanUploadResult) && !$canUploadResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult->addErrors($canUploadResult->getErrors()), $controller);
			}
			else if (!is_bool($canUploadResult) || $canUploadResult === false)
			{
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError(UploaderError::FILE_UPLOAD_ACCESS_DENIED)),
					$controller
				);
			}

			$createResult = TempFile::create($chunk, $controller, $strategy);
			if (!$createResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult->addErrors($createResult->getErrors()), $controller);
			}

			/** @var TempFile $tempFile */
			$tempFile = $createResult->getData()['tempFile'];
			$uploadResult->setTempFile($tempFile);
			$uploadResult->setToken($this->generateToken($tempFile));

			if ($tempFile->isParallel())
			{
				$uploadResult->setStrategy($tempFile->getStrategy());
				$uploadResult->setPartSize($tempFile->getPartSize());
				$uploadResult->setPartCount($tempFile->getPartCount());
			}

			$controller->onUploadStart($uploadResult);
			if (!$uploadResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult, $controller);
			}
		}
		else
		{
			if (empty($token))
			{
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError(UploaderError::EMPTY_TOKEN)),
					$controller
				);
			}

			$guid = $this->getGuidFromToken($token);
			if (!$guid)
			{
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError(UploaderError::INVALID_SIGNATURE)),
					$controller
				);
			}

			$tempFile = TempFileTable::getList([
				'filter' => [
					'=GUID' => $guid,
				],
			])->fetchObject();

			if (!$tempFile)
			{
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN)),
					$controller
				);
			}

			if ($tempFile->getUploaded())
			{
				// We already have the whole file
				$uploadResult->setToken($token);

				$fileInfo = $this->createFileInfo($uploadResult->getToken());
				$uploadResult->setFileInfo($fileInfo);
				$uploadResult->setDone(true);
			}
			else
			{
				$uploadResult->setTempFile($tempFile);
				$uploadResult->setToken($token);

				$appendResult = $tempFile->append($chunk);
				if (!$appendResult->isSuccess())
				{
					return $this->handleUploadError($uploadResult->addErrors($appendResult->getErrors()), $controller);
				}
			}
		}

		if ($uploadResult->isSuccess() && $chunk->isLast() && !$uploadResult->isDone())
		{
			$commitResult = $tempFile->commit($controller->getCommitOptions());
			if (!$commitResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult->addErrors($commitResult->getErrors()), $controller);
			}

			$fileInfo = $this->createFileInfo($uploadResult->getToken());
			$uploadResult->setFileInfo($fileInfo);
			$uploadResult->setDone(true);

			$controller->onUploadComplete($uploadResult);
			if (!$uploadResult->isSuccess())
			{
				return $this->handleUploadError($uploadResult, $controller);
			}
		}

		return $uploadResult;
	}

	/**
	 * Opens a presigned multipart session: validates the upload request, initiates
	 * a CreateMultipartUpload in the cloud and returns the metadata the client
	 * needs to start fetching presigned URLs for individual parts.
	 *
	 * No bytes are accepted here — the client will PUT each part directly to S3.
	 */
	public function initPresigned(FileData $fileData, ?int $partSize = null): UploadResult
	{
		$controller = $this->getController();
		$uploadResult = new UploadResult();

		if (!$this->isPresignedAvailable())
		{
			return $this->handleUploadError(
				$uploadResult->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED)),
				$controller
			);
		}

		if (\CFile::isImage($fileData->getName(), $fileData->getContentType()))
		{
			return $this->handleUploadError(
				$uploadResult->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED)),
				$controller
			);
		}

		$validateResult = $this->validatePresignedRequest($fileData, $controller);
		if (!$validateResult->isSuccess())
		{
			return $this->handleUploadError($uploadResult->addErrors($validateResult->getErrors()), $controller);
		}

		/** @var FileData $fileData */
		$fileData = $validateResult->getData()['fileData'];

		$createResult = TempFile::createPresigned($fileData, $controller, $partSize);
		if (!$createResult->isSuccess())
		{
			return $this->handleUploadError($uploadResult->addErrors($createResult->getErrors()), $controller);
		}

		/** @var TempFile $tempFile */
		$tempFile = $createResult->getData()['tempFile'];
		$uploadResult->setTempFile($tempFile);
		$token = $this->generateToken($tempFile);
		$uploadResult->setToken($token);
		$uploadResult->setStrategy($tempFile->getStrategy());
		$uploadResult->setPartSize($tempFile->getPartSize());
		$uploadResult->setPartCount($tempFile->getPartCount());
		$uploadResult->setPresignedParts($this->prefetchPresignedUrls($tempFile, $token));

		$controller->onUploadStart($uploadResult);
		if (!$uploadResult->isSuccess())
		{
			return $this->handleUploadError($uploadResult, $controller);
		}

		return $uploadResult;
	}

	/**
	 * Runs the pre-flight checks for a presigned-upload request: filename/MIME
	 * sanity, configured limits, image-specific rules, controller-level canUpload.
	 *
	 * Presigned uploads bypass PHP entirely, so width/height arrive from the
	 * client (ImagePreviewFilter on the JS side) and are NOT re-checked against
	 * the actual image bytes here. Sequential uploads cross-check via
	 * ConfigurationValidator::validateChunk → Image::getInfo on the received
	 * chunk; the presigned flow has no equivalent. Callers that need the
	 * stronger guarantee should re-inspect after commit.
	 *
	 * @return Result Result with data ['fileData' => FileData] on success (the
	 *  returned FileData may have width/height stripped for non-images).
	 */
	private function validatePresignedRequest(FileData $fileData, UploaderController $controller): Result
	{
		$result = new Result();

		$error = $this->checkFile($fileData->getName(), $fileData->getSize(), $fileData->getContentType());
		if ($error !== '')
		{
			return $result->addError(new UploaderError('CHECK_FILE_FAILED', $error));
		}

		// Basic file-level validation (filename, MIME, size against config).
		$configuration = $controller->getConfiguration();
		$validator = new ConfigurationValidator($configuration);
		$validationResult = $validator->validateFileData($fileData);
		if (!$validationResult->isSuccess())
		{
			return $result->addErrors($validationResult->getErrors());
		}

		// Strip width/height for non-images so they don't carry image-specific
		// metadata into b_file / FileInfo.
		$isImage = \CFile::isImage($fileData->getName(), $fileData->getContentType());
		if (!$isImage && ($fileData->getWidth() > 0 || $fileData->getHeight() > 0))
		{
			$fileData = new FileData($fileData->getName(), $fileData->getContentType(), $fileData->getSize());
		}

		if ($isImage)
		{
			if ($fileData->getWidth() === 0 || $fileData->getHeight() === 0)
			{
				if (!$configuration->getIgnoreUnknownImageTypes())
				{
					return $result->addError(new UploaderError(UploaderError::IMAGE_TYPE_NOT_SUPPORTED));
				}
			}
			else if (!$configuration->shouldTreatOversizeImageAsFile())
			{
				$imageValidation = $configuration->validateImage($fileData);
				if (!$imageValidation->isSuccess())
				{
					return $result->addErrors($imageValidation->getErrors());
				}
			}
		}

		$uploadRequest = new UploadRequest($fileData->getName(), $fileData->getContentType(), $fileData->getSize());
		$uploadRequest->setWidth($fileData->getWidth());
		$uploadRequest->setHeight($fileData->getHeight());

		$canUploadResult = call_user_func([$controller, 'canUpload'], $uploadRequest);
		if ($canUploadResult instanceof CanUploadResult)
		{
			if (!$canUploadResult->isSuccess())
			{
				return $result->addErrors($canUploadResult->getErrors());
			}
		}
		else if (!is_bool($canUploadResult) || $canUploadResult === false)
		{
			return $result->addError(new UploaderError(UploaderError::FILE_UPLOAD_ACCESS_DENIED));
		}

		return $result->setData(['fileData' => $fileData]);
	}

	/**
	 * Pre-signs URLs for (up to) the whole file upfront, so the client can upload
	 * every part without further round-trips. Capped at getPresignedUrlBatchLimit()
	 * — for files split into more parts, the client fetches the rest on demand via
	 * refreshPresignedUrls. With a 30-minute URL TTL the client is expected to use
	 * them within the window. Sign failures here are NON-fatal — the client falls
	 * back to on-demand fetching.
	 *
	 * @return array [['partNo' => N, 'url' => ..., 'expiresAt' => ts], ...]
	 */
	private function prefetchPresignedUrls(TempFile $tempFile, string $token): array
	{
		$partCount = (int)$tempFile->getPartCount();
		$prefetchCount = min($partCount, Configuration::getPresignedUrlBatchLimit());
		if ($prefetchCount < 1)
		{
			return [];
		}

		$prefetchResult = $this->refreshPresignedUrls($token, range(1, $prefetchCount));
		if (!$prefetchResult->isSuccess())
		{
			return [];
		}

		return $prefetchResult->getData()['parts'] ?? [];
	}

	/**
	 * Generates fresh presigned PUT URLs for the requested parts. Used both for
	 * the first batch (client doesn't get URLs from initPresigned) and for any
	 * subsequent re-sign needed when an URL expires mid-upload.
	 *
	 * @param string $token Session token issued by initPresigned.
	 * @param int[] $partNumbers 1-based part numbers to (re-)sign.
	 * @return Result Result with data ['parts' => [['partNo' => N, 'url' => ..., 'expiresAt' => ts], ...]].
	 */
	public function refreshPresignedUrls(string $token, array $partNumbers): Result
	{
		$result = new Result();

		if (!$this->isPresignedAvailable())
		{
			return $result->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED));
		}

		$tempFile = $this->resolvePresignedTempFile($token, $result);
		if ($tempFile === null)
		{
			return $result;
		}

		$partCount = (int)$tempFile->getPartCount();
		$partSize = (int)$tempFile->getPartSize();
		$size = (int)$tempFile->getSize();
		$ttl = Configuration::getPresignedUrlTtl();
		$expiresAt = time() + $ttl;
		$cloudUpload = new \CCloudStorageUpload($tempFile->getPath());

		$partNumbers = array_slice(
			array_values(array_unique(array_map('intval', $partNumbers))),
			0,
			Configuration::getPresignedUrlBatchLimit()
		);

		$parts = [];
		foreach ($partNumbers as $partNo)
		{
			$partNo = (int)$partNo;
			if ($partNo < 1 || $partNo > $partCount)
			{
				return $result->addError(new UploaderError(
					UploaderError::INVALID_PART_NO,
					['partNo' => $partNo, 'partCount' => $partCount],
				));
			}

			$expectedSize = ($partNo === $partCount) ? ($size - ($partCount - 1) * $partSize) : $partSize;
			$url = $cloudUpload->presignPart($partNo, $ttl, null, $expectedSize);
			if ($url === null)
			{
				return $result->addError(new UploaderError(
					UploaderError::PRESIGNED_URL_FAILED,
					['partNo' => $partNo],
				));
			}

			$parts[] = [
				'partNo' => $partNo,
				'url' => $url,
				'expiresAt' => $expiresAt,
			];
		}

		return $result->setData(['parts' => $parts]);
	}

	/**
	 * Records a part's ETag returned by S3 after a successful direct PUT. Idempotent —
	 * a repeated registration for the same partNo updates the stored ETag (which is
	 * useful when the client retried the upload and got a fresh ETag from S3).
	 *
	 * @return Result Result with data ['partsReceived' => int, 'partCount' => int, 'done' => bool].
	 */
	public function registerPresignedPart(string $token, int $partNo, string $etag): Result
	{
		$result = new Result();

		if (!$this->isPresignedAvailable())
		{
			return $result->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED));
		}

		$tempFile = $this->resolvePresignedTempFile($token, $result);
		if ($tempFile === null)
		{
			return $result;
		}

		$partCount = (int)$tempFile->getPartCount();
		if ($partNo < 1 || $partNo > $partCount)
		{
			return $result->addError(new UploaderError(
				UploaderError::INVALID_PART_NO,
				['partNo' => $partNo, 'partCount' => $partCount],
			));
		}

		$etag = trim($etag);
		if ($etag === '' || mb_strlen($etag) > 64 || preg_match('/[<>&]/', $etag))
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_ETAG, ['partNo' => $partNo]));
		}

		// Idempotent upsert: re-registration of the same partNo updates ETag
		// (e.g. after a retry that returned a different ETag from S3).
		$existing = TempFilePartTable::getList([
			'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '=PART_NO' => $partNo],
			'select' => ['ID'],
			'limit' => 1,
		])->fetchObject();

		if ($existing)
		{
			TempFilePartTable::update($existing->getId(), ['ETAG' => $etag]);
		}
		else
		{
			$addResult = TempFilePartTable::add([
				'TEMP_FILE_ID' => $tempFile->getId(),
				'PART_NO' => $partNo,
				'ETAG' => $etag,
			]);
			if (!$addResult->isSuccess())
			{
				// A concurrent register for the same partNo may have inserted the row
				// between the SELECT above and this ADD (UNIQUE(TEMP_FILE_ID, PART_NO)).
				// Treat that as an idempotent update instead of a hard failure that
				// would abort finalization on a harmless race.
				$conflicting = TempFilePartTable::getList([
					'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '=PART_NO' => $partNo],
					'select' => ['ID'],
					'limit' => 1,
				])->fetchObject();

				if (!$conflicting)
				{
					return $result->addErrors($addResult->getErrors());
				}

				TempFilePartTable::update($conflicting->getId(), ['ETAG' => $etag]);
			}
		}

		// COUNT(*) WHERE ETAG IS NOT NULL — to handle a slim possibility that some
		// part rows ended up without ETag (would not normally happen for presigned).
		$received = TempFilePartTable::getList([
			'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '!=ETAG' => null],
			'count_total' => true,
			'limit' => 0,
		])->getCount();

		return $result->setData([
			'partsReceived' => (int)$received,
			'partCount' => $partCount,
			'done' => ((int)$received === $partCount),
		]);
	}

	public function registerPresignedParts(string $token, array $parts): Result
	{
		$result = new Result();

		if (!$this->isPresignedAvailable())
		{
			return $result->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED));
		}

		$tempFile = $this->resolvePresignedTempFile($token, $result);
		if ($tempFile === null)
		{
			return $result;
		}

		$partCount = (int)$tempFile->getPartCount();
		$tempFileId = (int)$tempFile->getId();

		$etagByPartNo = [];
		foreach ($parts as $part)
		{
			if (!is_array($part))
			{
				continue;
			}

			$partNo = (int)($part['partNo'] ?? 0);
			$etag = trim((string)($part['etag'] ?? ''));
			if ($partNo <= 0 || $etag === '')
			{
				continue;
			}

			if ($partNo > $partCount)
			{
				return $result->addError(new UploaderError(
					UploaderError::INVALID_PART_NO,
					['partNo' => $partNo, 'partCount' => $partCount],
				));
			}

			if (mb_strlen($etag) > 64 || preg_match('/[<>&]/', $etag))
			{
				return $result->addError(new UploaderError(UploaderError::INVALID_ETAG, ['partNo' => $partNo]));
			}

			$etagByPartNo[$partNo] = $etag;
		}

		$batchLimit = Configuration::getPresignedUrlBatchLimit();
		foreach (array_chunk($etagByPartNo, $batchLimit, true) as $etagBatch)
		{
			$existingIdByPartNo = [];
			$existingRows = TempFilePartTable::getList([
				'filter' => ['=TEMP_FILE_ID' => $tempFileId, '@PART_NO' => array_keys($etagBatch)],
				'select' => ['ID', 'PART_NO'],
			])->fetchAll();

			foreach ($existingRows as $row)
			{
				$existingIdByPartNo[(int)$row['PART_NO']] = (int)$row['ID'];
			}

			$rowsToInsert = [];
			foreach ($etagBatch as $partNo => $etag)
			{
				if (isset($existingIdByPartNo[$partNo]))
				{
					// Re-registration: a retry returned a different ETag for a stored part.
					TempFilePartTable::update($existingIdByPartNo[$partNo], ['ETAG' => $etag]);
				}
				else
				{
					$rowsToInsert[$partNo] = [
						'TEMP_FILE_ID' => $tempFileId,
						'PART_NO' => $partNo,
						'ETAG' => $etag,
					];
				}
			}

			if (!empty($rowsToInsert))
			{
				$insertResult = $this->insertPresignedParts($rowsToInsert, $etagBatch);
				if (!$insertResult->isSuccess())
				{
					return $result->addErrors($insertResult->getErrors());
				}
			}
		}

		// Single readiness COUNT for the whole batch (was one per part).
		$received = TempFilePartTable::getList([
			'filter' => ['=TEMP_FILE_ID' => $tempFileId, '!=ETAG' => null],
			'count_total' => true,
			'limit' => 0,
		])->getCount();

		return $result->setData([
			'partsReceived' => (int)$received,
			'partCount' => $partCount,
			'done' => ((int)$received === $partCount),
		]);
	}

	/**
	 * Bulk-inserts new presigned part rows in a single statement. On a UNIQUE
	 * (TEMP_FILE_ID, PART_NO) race with a concurrent register the multi-row INSERT
	 * aborts as a whole, so we fall back to a per-row upsert that treats an
	 * already-present row as an idempotent ETag update rather than a hard failure
	 * (which would otherwise abort finalization on a harmless race).
	 *
	 * @param array<int, array> $rowsToInsert partNo => row fields
	 * @param array<int, string> $etagByPartNo partNo => etag
	 */
	private function insertPresignedParts(array $rowsToInsert, array $etagByPartNo): Result
	{
		$result = new Result();

		try
		{
			$addResult = TempFilePartTable::addMulti(array_values($rowsToInsert), true);
			if ($addResult->isSuccess())
			{
				return $result;
			}
		}
		catch (\Bitrix\Main\DB\SqlQueryException $e)
		{
			// Fall through to per-row reconciliation below.
		}

		// Slow path (rare): a concurrent register collided on the UNIQUE index.
		// Reconcile each row individually, upserting on conflict.
		foreach ($rowsToInsert as $partNo => $row)
		{
			$existing = TempFilePartTable::getList([
				'filter' => ['=TEMP_FILE_ID' => $row['TEMP_FILE_ID'], '=PART_NO' => $partNo],
				'select' => ['ID'],
				'limit' => 1,
			])->fetchObject();

			if ($existing)
			{
				TempFilePartTable::update($existing->getId(), ['ETAG' => $etagByPartNo[$partNo]]);

				continue;
			}

			$addResult = TempFilePartTable::add($row);
			if (!$addResult->isSuccess())
			{
				$conflicting = TempFilePartTable::getList([
					'filter' => ['=TEMP_FILE_ID' => $row['TEMP_FILE_ID'], '=PART_NO' => $partNo],
					'select' => ['ID'],
					'limit' => 1,
				])->fetchObject();

				if (!$conflicting)
				{
					return $result->addErrors($addResult->getErrors());
				}

				TempFilePartTable::update($conflicting->getId(), ['ETAG' => $etagByPartNo[$partNo]]);
			}
		}

		return $result;
	}

	/**
	 * Finalizes a presigned multipart upload. Reconstructs S3 Parts state from
	 * the application's parts table, runs CompleteMultipartUpload via the cloud
	 * service, and commits the resulting object into b_file.
	 *
	 * Idempotent: repeated calls after a successful finalization return the
	 * existing FileInfo (handles late completePresigned retries on flaky networks).
	 *
	 * @return UploadResult done=true + FileInfo on success.
	 */
	public function completePresigned(string $token, array $parts = []): UploadResult
	{
		$controller = $this->getController();
		$uploadResult = new UploadResult();

		if (!$this->isPresignedAvailable())
		{
			return $this->handleUploadError(
				$uploadResult->addError(new UploaderError(UploaderError::PRESIGNED_UNSUPPORTED)),
				$controller
			);
		}

		$guid = $this->getGuidFromToken($token);
		if (!$guid)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
		}

		$tempFile = TempFileTable::getList(['filter' => ['=GUID' => $guid]])->fetchObject();
		if (!$tempFile)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));
		}

		$uploadResult->setTempFile($tempFile);
		$uploadResult->setToken($token);

		if ($tempFile->getStrategy() !== TempFile::STRATEGY_PRESIGNED)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::WRONG_STRATEGY));
		}

		if ($this->isFinalized($tempFile))
		{
			$fileInfo = $this->createFileInfo($token);
			$uploadResult->setFileInfo($fileInfo);
			$uploadResult->setDone(true);

			return $uploadResult;
		}

		// The client registers ETags during the upload (throttled, via registerParts)
		// and sends whatever is still unregistered here. Idempotent — re-registering
		// an already-stored partNo just updates its ETag.
		$registerResult = $this->registerPresignedParts($token, $parts);
		if (!$registerResult->isSuccess())
		{
			return $uploadResult->addErrors($registerResult->getErrors());
		}

		$partCount = (int)$tempFile->getPartCount();

		// Gather all registered ETags in part-number order. If any part is missing,
		// we cannot finalize yet — the client must finish uploading first.
		$registeredParts = TempFilePartTable::getList([
			'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '!=ETAG' => null],
			'select' => ['PART_NO', 'ETAG'],
			'order' => ['PART_NO' => 'ASC'],
		])->fetchAll();

		if (count($registeredParts) < $partCount)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::FINALIZATION_NOT_READY));
		}

		$partsMap = [];
		foreach ($registeredParts as $row)
		{
			$partsMap[(int)$row['PART_NO']] = (string)$row['ETAG'];
		}

		// Acquire the finalization lease. Wins exactly one concurrent caller; the
		// rest see affected_rows=0 and bail out gracefully (the winner does commit).
		$capturedRows = TempFileTable::updateByFilter(
			['=ID' => $tempFile->getId(), '=UPLOADED' => false],
			['UPLOADED' => true],
		);

		if ($capturedRows !== 1)
		{
			$tempFile = TempFileTable::getList(['filter' => ['=ID' => $tempFile->getId()]])->fetchObject();
			if ($tempFile && $this->isFinalized($tempFile))
			{
				$fileInfo = $this->createFileInfo($token);
				$uploadResult->setFileInfo($fileInfo);
				$uploadResult->setDone(true);

				return $uploadResult;
			}

			return $uploadResult->addError(new UploaderError(UploaderError::FINALIZATION_IN_PROGRESS));
		}

		// Repopulate NEXT_STEP.Parts in clouds, then ask clouds to call CompleteMultipartUpload.
		$cloudUpload = new \CCloudStorageUpload($tempFile->getPath());
		if (!$cloudUpload->setParts($partsMap) || !$cloudUpload->finish())
		{
			// All bytes are already in S3 and every ETag is registered — only the
			// finalize step (CompleteMultipartUpload) failed. Release the lease
			// instead of destroying the session, so a completePresigned retry can
			// re-attempt finalization (idempotent on the S3 side) rather than forcing
			// a full re-upload. A session the client never retries is reclaimed by the
			// GC agents (CCloudStorageUpload::CleanUp + TempFileAgent).
			TempFileTable::updateByFilter(
				['=ID' => $tempFile->getId(), '=UPLOADED' => true],
				['UPLOADED' => false],
			);

			return $uploadResult->addError(new UploaderError(
				UploaderError::CLOUD_FINISH_UPLOAD_FAILED,
				['detail' => $this->getCloudExceptionMessage()]
			));
		}

		// commit() runs CFile::saveFile with tmp_name = public cloud URL — no real copy.
		$commitResult = $tempFile->commit($controller->getCommitOptions());
		if (!$commitResult->isSuccess())
		{
			return $uploadResult->addErrors($commitResult->getErrors());
		}

		TempFilePartTable::deleteByFilter(['=TEMP_FILE_ID' => $tempFile->getId()]);

		$fileInfo = $this->createFileInfo($token);
		$uploadResult->setFileInfo($fileInfo);
		$uploadResult->setDone(true);

		$controller->onUploadComplete($uploadResult);
		if (!$uploadResult->isSuccess())
		{
			return $this->handleUploadError($uploadResult, $controller);
		}

		return $uploadResult;
	}

	private function isPresignedAvailable(): bool
	{
		return Configuration::isPresignedChunkUploadEnabled() && Loader::includeModule('clouds');
	}

	private function isFinalized(TempFile $tempFile): bool
	{
		return $tempFile->getUploaded() && (int)$tempFile->getFileId() > 0;
	}

	/**
	 * Loads a TempFile by token and verifies it belongs to a presigned session.
	 * Pushes errors into $result on failure and returns null.
	 */
	private function resolvePresignedTempFile(string $token, Result $result): ?TempFile
	{
		$guid = $this->getGuidFromToken($token);
		if (!$guid)
		{
			$result->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));

			return null;
		}

		$tempFile = TempFileTable::getList(['filter' => ['=GUID' => $guid]])->fetchObject();
		if (!$tempFile)
		{
			$result->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));

			return null;
		}

		if ($tempFile->getStrategy() !== TempFile::STRATEGY_PRESIGNED)
		{
			$result->addError(new UploaderError(UploaderError::WRONG_STRATEGY));

			return null;
		}

		return $tempFile;
	}

	/**
	 * Accepts a single part of a parallel upload. Idempotent — a repeated POST
	 * with the same partNo does not write twice (protected by the PK of the parts table).
	 * The last accepted part triggers automatic finalization (commit when RECEIVED_SIZE == SIZE).
	 */
	public function uploadPart(Chunk $chunk, string $token, int $partNo): UploadResult
	{
		$controller = $this->getController();
		$uploadResult = new UploadResult();

		$guid = $this->getGuidFromToken($token);
		if (!$guid)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
		}

		$tempFile = TempFileTable::getList([
			'filter' => ['=GUID' => $guid],
		])->fetchObject();

		if (!$tempFile)
		{
			return $uploadResult->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));
		}

		$uploadResult->setTempFile($tempFile);
		$uploadResult->setToken($token);

		if (!$tempFile->isParallel())
		{
			$chunk->getFile()->delete();
			return $uploadResult->addError(new UploaderError(UploaderError::WRONG_STRATEGY));
		}

		if ($this->isFinalized($tempFile))
		{
			$chunk->getFile()->delete();
			$fileInfo = $this->createFileInfo($token);
			$uploadResult->setFileInfo($fileInfo);
			$uploadResult->setDone(true);

			return $uploadResult;
		}

		$partSize = (int)$tempFile->getPartSize();
		$partCount = (int)$tempFile->getPartCount();

		// partNo is 1-based: aligned with S3 PartNumber and CCloudStorageUpload::Part.
		if ($partNo < 1 || $partNo > $partCount)
		{
			$chunk->getFile()->delete();
			return $uploadResult->addError(new UploaderError(
				UploaderError::INVALID_PART_NO,
				['partNo' => $partNo, 'partCount' => $partCount]
			));
		}

		$isLastPart = ($partNo === $partCount);
		$expectedSize = $isLastPart ? ($tempFile->getSize() - ($partNo - 1) * $partSize) : $partSize;

		if ($chunk->getSize() !== $expectedSize)
		{
			$chunk->getFile()->delete();
			return $uploadResult->addError(new UploaderError(
				UploaderError::INVALID_PART_SIZE,
				['partNo' => $partNo, 'actualSize' => $chunk->getSize(), 'expectedSize' => $expectedSize]
			));
		}

		// Fast-path idempotency (no lock): an already-accepted part exits without writing.
		$existing = TempFilePartTable::getList([
			'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '=PART_NO' => $partNo],
			'select' => ['PART_NO'],
			'limit' => 1,
		])->fetchObject();

		if ($existing)
		{
			$chunk->getFile()->delete();
			return $uploadResult;
		}

		// Serialize concurrent requests for the SAME partNo. The write must own the
		// partNo *before* it touches storage: otherwise two requests could both pass
		// the existing-check, both write (possibly different bytes for a misbehaving
		// client) to the same offset / S3 PartNumber, and the INSERT loser would still
		// return success — leaving the on-disk bytes decoupled from the part the DB
		// recorded.
		$connection = Application::getConnection();
		$lockName = 'UI::FileUploader::uploadPart(' . $tempFile->getId() . ':' . $partNo . ')';
		$connection->lock($lockName, -1);

		try
		{
			// Re-check under the lock: a concurrent writer may have committed this part
			// while we waited for the lock.
			$existing = TempFilePartTable::getList([
				'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '=PART_NO' => $partNo],
				'select' => ['PART_NO'],
				'limit' => 1,
			])->fetchObject();

			if ($existing)
			{
				$chunk->getFile()->delete();
				return $uploadResult;
			}

			$writeResult = (
				$tempFile->isCloud()
					? $tempFile->writePartCloud($chunk, $partNo)
					: $tempFile->writePartLocal($chunk, $partNo)
			);

			if (!$writeResult->isSuccess())
			{
				$chunk->getFile()->delete();

				return $uploadResult->addErrors($writeResult->getErrors());
			}

			// Register the part + atomically increment RECEIVED_SIZE in a single
			// transaction to avoid a half-applied state on DB failure.
			$connection->startTransaction();
			try
			{
				$partAddResult = TempFilePartTable::add([
					'TEMP_FILE_ID' => $tempFile->getId(),
					'PART_NO' => $partNo,
				]);

				if (!$partAddResult->isSuccess())
				{
					$connection->rollbackTransaction();
					$chunk->getFile()->delete();

					$alreadyAccepted = TempFilePartTable::getList([
						'filter' => ['=TEMP_FILE_ID' => $tempFile->getId(), '=PART_NO' => $partNo],
						'select' => ['PART_NO'],
						'limit' => 1,
					])->fetchObject();

					if ($alreadyAccepted)
					{
						return $uploadResult;
					}

					return $uploadResult->addErrors($partAddResult->getErrors());
				}

				TempFileTable::update(
					$tempFile->getId(),
					[
						'RECEIVED_SIZE' => new SqlExpression('?# + ?i', 'RECEIVED_SIZE', $chunk->getSize()),
					]
				);

				$connection->commitTransaction();
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();
				$chunk->getFile()->delete();

				throw $e;
			}
		}
		finally
		{
			$connection->unlock($lockName);
		}

		$chunk->getFile()->delete();

		// Acquire the finalization lease: atomic UPDATE conditioned on
		// RECEIVED_SIZE == SIZE and UPLOADED == 0. Whoever wins (affected_rows = 1)
		// performs the finalization. Other concurrent requests get 0 and exit quietly.
		$capturedRows = TempFileTable::updateByFilter(
			[
				'=ID' => $tempFile->getId(),
				'=UPLOADED' => false,
				'=RECEIVED_SIZE' => new SqlExpression('?#', 'SIZE'),
			],
			[
				'UPLOADED' => true,
			]
		);

		if ($capturedRows !== 1)
		{
			// Either not all parts have arrived yet, or another process is already finalizing.
			return $uploadResult;
		}

		return $this->finalizeParallel($uploadResult, $controller, $token);
	}

	/**
	 * Finalizes a parallel upload. Called by exactly one process — the one that
	 * acquired the lease via the atomic UPDATE in uploadPart().
	 */
	/**
	 * Pulls the last exception raised by the clouds module (CCloudStorageUpload::Finish
	 * throws it via $APPLICATION->ThrowException with the storage service's own error
	 * text — e.g. EntityTooSmall / InvalidPart / NoSuchUpload). Surfaced in
	 * CLOUD_FINISH_UPLOAD_FAILED so a finish failure is diagnosable instead of a bare
	 * "server error".
	 */
	private function getCloudExceptionMessage(): string
	{
		global $APPLICATION;

		$exception = $APPLICATION->GetException();

		return $exception ? trim($exception->GetString()) : '';
	}

	private function finalizeParallel(UploadResult $uploadResult, UploaderController $controller, string $token): UploadResult
	{
		// Reload TempFile to see fresh UPLOADED=1 and RECEIVED_SIZE=SIZE.
		$tempFileId = $uploadResult->getTempFile()->getId();
		$tempFile = TempFileTable::getList([
			'filter' => ['=ID' => $tempFileId],
		])->fetchObject();

		if (!$tempFile)
		{
			// Race with TempFileAgent::clearOldRecords / manual remove() — exit quietly.
			return $uploadResult;
		}

		$uploadResult->setTempFile($tempFile);

		// Cloud: complete the multipart session. After finish() the object becomes
		// a "real" file at the same path, and getFileSRC() returns a working URL.
		if ($tempFile->isCloud())
		{
			$cloudUpload = new \CCloudStorageUpload($tempFile->getPath());
			if (!$cloudUpload->finish())
			{
				// On finish() failure — discard TempFile (the cloud object is removed
				// by removeActualTempFile via $bucket->deleteFile).
				return $this->handleUploadError(
					$uploadResult->addError(new UploaderError(
						UploaderError::CLOUD_FINISH_UPLOAD_FAILED,
						['detail' => $this->getCloudExceptionMessage()]
					)),
					$controller
				);
			}
		}

		// CFile::saveFile. For cloud, tmp_name is the public URL — no copy is performed.
		$commitResult = $tempFile->commit($controller->getCommitOptions());
		if (!$commitResult->isSuccess())
		{
			// commit() removes TempFile itself on saveFile failure — do not call delete again.
			return $uploadResult->addErrors($commitResult->getErrors());
		}

		// Drop accepted-parts records: after commit they have no role. Late-arriving
		// retries are handled by the $tempFile->getUploaded() early-exit in uploadPart().
		TempFilePartTable::deleteByFilter(['=TEMP_FILE_ID' => $tempFile->getId()]);

		$fileInfo = $this->createFileInfo($token);
		$uploadResult->setFileInfo($fileInfo);
		$uploadResult->setDone(true);

		$controller->onUploadComplete($uploadResult);
		if (!$uploadResult->isSuccess())
		{
			return $this->handleUploadError($uploadResult, $controller);
		}

		return $uploadResult;
	}

	private function handleUploadError(UploadResult $uploadResult, UploaderController $controller): UploadResult
	{
		$controller->onUploadError($uploadResult);

		if (!$uploadResult->isSuccess())
		{
			$tempFile = $uploadResult->getTempFile();
			if ($tempFile !== null && $tempFile->state !== State::DELETED)
			{
				$tempFile->delete();
			}
		}

		return $uploadResult;
	}

	/**
	 * Wraps CFile::checkFile with the controller's CommitOptions (forceRandom /
	 * skipExtension), so callers do not have to reach into the controller every
	 * time. Returns the raw error string from CFile if anything failed.
	 */
	private function checkFile(string $name, int $size, string $type): string
	{
		$commitOptions = $this->getController()->getCommitOptions();

		return \CFile::checkFile(
			[
				'name' => $name,
				'size' => $size,
				'type' => $type,
			],
			0,
			false,
			false,
			$commitOptions->isForceRandom(),
			$commitOptions->isSkipExtension()
		);
	}

	public function generateToken(TempFile $tempFile): string
	{
		$guid = $tempFile->getGuid();
		$salt = $this->getTokenSalt([$guid]);
		$signer = new Signer();

		return $signer->sign($guid, $salt);
	}

	private function getGuidFromToken(string $token): ?string
	{
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2)
		{
			return null;
		}

		[$guid, $signature] = $parts;
		if (empty($guid) || empty($signature))
		{
			return null;
		}

		$salt = $this->getTokenSalt([$guid]);
		$signer = new Signer();

		if (!$signer->validate($guid, $signature, $salt))
		{
			return null;
		}

		return $guid;
	}

	private function getTokenSalt($params = []): string
	{
		$controller = $this->getController();
		$options = $controller->getOptions();
		ksort($options);

		$fingerprint =
			$controller instanceof CustomFingerprint
				? $controller->getFingerprint()
				: (string)\bitrix_sessid()
		;

		return md5(serialize(
			array_merge(
				$params,
				[
					$controller->getName(),
					$options,
					$fingerprint,
				]
			)
		));
	}

	public function load(array $ids): LoadResultCollection
	{
		$controller = $this->getController();
		if ($controller instanceof CustomLoad)
		{
			return $controller->load($ids);
		}

		$results = new LoadResultCollection();
		[$bfileIds, $tempFileIds] = $this->splitIds($ids);
		$fileOwnerships = new FileOwnershipCollection($bfileIds);

		// Files from b_file
		if ($fileOwnerships->count() > 0)
		{
			$controller = $this->getController();
			if ($controller->canView())
			{
				$controller->verifyFileOwner($fileOwnerships);
			}

			foreach ($fileOwnerships as $fileOwnership)
			{
				if ($fileOwnership->isOwn())
				{
					$loadResult = $this->loadFile($fileOwnership->getId());
				}
				else
				{
					$loadResult = new LoadResult($fileOwnership->getId());
					$loadResult->addError(new UploaderError(UploaderError::FILE_LOAD_ACCESS_DENIED));
				}

				$results->add($loadResult);
			}
		}

		// Temp Files
		if (count($tempFileIds) > 0)
		{
			foreach ($tempFileIds as $tempFileId)
			{
				$loadResult = $this->loadTempFile($tempFileId);
				$results->add($loadResult);
			}
		}

		return $results;
	}

	public function getFileInfo(array $ids): array
	{
		$result = [];
		$loadResults = $this->load(array_unique($ids));
		foreach ($loadResults as $loadResult)
		{
			if ($loadResult->isSuccess() && $loadResult->getFile() !== null)
			{
				$result[] = $loadResult->getFile()->jsonSerialize();
			}
		}

		return $result;
	}

	public function remove(array $ids): RemoveResultCollection
	{
		$controller = $this->getController();
		if ($controller instanceof CustomRemove)
		{
			return $controller->remove($ids);
		}

		$results = new RemoveResultCollection();
		[$bfileIds, $tempFileIds] = $this->splitIds($ids);

		// Files from b_file
		if (count($bfileIds) > 0)
		{
			$fileOwnerships = new FileOwnershipCollection($bfileIds);
			if ($controller->canRemove())
			{
				$controller->verifyFileOwner($fileOwnerships);
			}

			foreach ($fileOwnerships as $fileOwnership)
			{
				$removeResult = new RemoveResult($fileOwnership->getId());
				if ($fileOwnership->isOwn())
				{
					// TODO:  remove file
				}
				else
				{
					$removeResult->addError(new UploaderError(UploaderError::FILE_REMOVE_ACCESS_DENIED));
				}

				$results->add($removeResult);
			}
		}

		// Temp Files
		if (count($tempFileIds) > 0)
		{
			foreach ($tempFileIds as $tempFileId)
			{
				$removeResult = new RemoveResult($tempFileId);
				$results->add($removeResult);

				$guid = $this->getGuidFromToken($tempFileId);
				if (!$guid)
				{
					$removeResult->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
					continue;
				}

				$tempFile = TempFileTable::getList([
					'filter' => [
						'=GUID' => $guid,
					],
				])->fetchObject();

				if ($tempFile)
				{
					$tempFile->delete();
				}
			}
		}

		return $results;
	}

	public function getPendingFiles(array $tempFileIds): PendingFileCollection
	{
		$pendingFiles = new PendingFileCollection();
		foreach ($tempFileIds as $tempFileId)
		{
			if (!is_string($tempFileId) || empty($tempFileId))
			{
				continue;
			}

			$pendingFile = new PendingFile($tempFileId);
			$pendingFiles->add($pendingFile);

			$guid = $this->getGuidFromToken($tempFileId);
			if (!$guid)
			{
				$pendingFile->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));

				continue;
			}

			$tempFile = TempFileTable::getList([
				'filter' => [
					'=GUID' => $guid,
					'=UPLOADED' => true,
				],
			])->fetchObject();

			if (!$tempFile)
			{
				$pendingFile->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));

				continue;
			}

			$pendingFile->setTempFile($tempFile);
		}

		return $pendingFiles;
	}

	public function getStatus(string $token): StatusResult
	{
		$statusResult = new StatusResult();
		$guid = $this->getGuidFromToken($token);

		if (!$guid)
		{
			return $statusResult->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
		}

		$tempFile = TempFileTable::getList([
			'filter' => [
				'=GUID' => $guid,
			],
		])->fetchObject();

		if (!$tempFile)
		{
			return $statusResult->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));
		}

		$finalized = $this->isFinalized($tempFile);
		$statusResult->setDone($finalized);
		$statusResult->setToken($token);
		$statusResult->setSize($tempFile->getSize());
		$statusResult->setReceivedSize($tempFile->getReceivedSize());

		if ($finalized)
		{
			$fileInfo = $this->createFileInfo($statusResult->getToken());
			$statusResult->setFileInfo($fileInfo);
		}

		return $statusResult;
	}

	private function loadFile(int $fileId): LoadResult
	{
		$result = new LoadResult($fileId);
		if ($fileId < 1)
		{
			return $result->addError(new UploaderError(UploaderError::FILE_LOAD_FAILED));
		}

		$fileInfo = $this->createFileInfo($fileId);
		if ($fileInfo)
		{
			$result->setFile($fileInfo);
		}
		else
		{
			return $result->addError(new UploaderError(UploaderError::FILE_LOAD_FAILED));
		}

		return $result;
	}

	private function loadTempFile(string $tempFileId): LoadResult
	{
		$result = new LoadResult($tempFileId);
		$guid = $this->getGuidFromToken($tempFileId);
		if (!$guid)
		{
			return $result->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
		}

		$tempFile = TempFileTable::getList([
			'filter' => [
				'=GUID' => $guid,
				'=UPLOADED' => true,
			],
		])->fetchObject();

		if (!$tempFile)
		{
			return $result->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));
		}

		$fileInfo = $this->createFileInfo($tempFileId);
		if ($fileInfo)
		{
			$result->setFile($fileInfo);
		}
		else
		{
			return $result->addError(new UploaderError(UploaderError::FILE_LOAD_FAILED));
		}

		return $result;
	}

	private function createFileInfo($fileId): ?FileInfo
	{
		$fileInfo = is_int($fileId) ? FileInfo::createFromBFile($fileId) : FileInfo::createFromTempFile($fileId);
		if ($fileInfo)
		{
			$downloadUrl = (string)UrlManager::getDownloadUrl($this->getController(), $fileInfo);
			$fileInfo->setDownloadUrl($downloadUrl);

			$fileInfo->setViewerAttrs($this->prepareViewerAttrs($fileInfo, $downloadUrl));

			if ($fileInfo->isImage())
			{
				$config = $this->getController()->getConfiguration();
				if ($config->shouldTreatOversizeImageAsFile())
				{
					$treatImageAsFile = $config->shouldTreatImageAsFile($fileInfo);
					$fileInfo->setTreatImageAsFile($treatImageAsFile);
				}

				if (!$fileInfo->shouldTreatImageAsFile())
				{
					$rectangle = PreviewImage::getSize($fileInfo);
					$previewUrl = (string)UrlManager::getPreviewUrl($this->getController(), $fileInfo);
					$fileInfo->setPreviewUrl($previewUrl, $rectangle->getWidth(), $rectangle->getHeight());
				}
			}
		}

		return $fileInfo;
	}

	private function prepareViewerAttrs(FileInfo $fileInfo, string $downloadUrl): array
	{
		$fileData = [
			'ID' => $fileInfo->getId(),
			'CONTENT_TYPE' => $fileInfo->getContentType(),
			'ORIGINAL_NAME' => $fileInfo->getName(),
			'WIDTH' => $fileInfo->getWidth(),
			'HEIGHT' => $fileInfo->getHeight(),
			'FILE_SIZE' => $fileInfo->getSize(),
		];

		return ItemAttributes::buildByFileData($fileData, $downloadUrl)
			->setTitle($fileInfo->getName())
			->toDataSet()
		;
	}

	private function splitIds(array $ids): array
	{
		$fileIds = [];
		$tempFileIds = [];
		foreach ($ids as $id)
		{
			if (is_numeric($id))
			{
				$fileIds[] = (int)$id;
			}
			else
			{
				$tempFileIds[] = (string)$id;
			}
		}

		return [$fileIds, $tempFileIds];
	}
}
