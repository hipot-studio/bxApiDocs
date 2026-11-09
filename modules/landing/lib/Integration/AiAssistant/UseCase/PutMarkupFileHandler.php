<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\PutMarkupFileDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteModeGuard;
use Bitrix\Landing\Markup\Submission\EditTargetResolver;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Markup\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Per-file intake into the upload buffer. Path rules, the file size limit and the package
 * limits the buffer already approaches are enforced here, on file arrival - a broken file
 * is reported immediately, not at submit time, and a refused file leaves the buffer as it was.
 * The html-asset ban stays with the package validation: file roles are known only at submission.
 * An expired buffer answers MARKUP_UPLOAD_EXPIRED: the skill opens a new one.
 *
 * A file arrives whole (content), in base64 parts (content with offset, final=true with sha256
 * glues them) or from a Disk object (diskFileId). A call naming only the uploadId lists what
 * the buffer holds. Every answer carries chunkBytes, the write part size limit in base64 characters.
 *
 * An update buffer is opened by the binding of the user: the edit target resolver finds the page
 * the buffer is bound to, a refusal of the resolver opens nothing. An open buffer keeps naming its
 * page, so a continuation by uploadId takes the target of the record.
 */
class PutMarkupFileHandler extends AbstractSiteHandler
{
	public const UPDATE_BINDING_REQUIRED_ERROR = 'bindingId is required to open an update upload buffer.';

	private const UPLOAD_NOT_FOUND_MESSAGE = 'Upload buffer was not found or belongs to another user.';

	private const SOURCE_CONTENT = 'content';
	private const SOURCE_PART = 'part';
	private const SOURCE_DISK = 'disk';

	// the file is passed again as the code says; the other refusals need another file or a new buffer
	private const REPAIRABLE_CODES = [UploadBuffer::ERROR_CHUNK_MISMATCH, UploadBuffer::ERROR_BASE64_INVALID];

	/**
	 * The buffer lookup of resolveTarget(), taken over by the handle() of the same call: the buffer row
	 * with all its files is read once before the change, the change itself re-reads it under the lock.
	 * @var array{uploadId: string, userId: int, found: Result}|null
	 */
	private ?array $lookup = null;

	/**
	 * Resolves the target the caller must be permitted for: the target of the existing buffer, or
	 * the target of the buffer about to open. A buffer about to open names the binding only: its page
	 * is found by the resolver on the open, so pageId is null there. An identifier the target does not
	 * name is null: an update buffer keeps its page only, a creation buffer its binding only.
	 * @return array{mode: string, bindingId: ?int, pageId: ?int}
	 */
	public function resolveTarget(PutMarkupFileDto $dto, int $userId): array
	{
		if ($dto->uploadId !== '')
		{
			// an expired buffer still names its target: the rights on it are checked before the refusal
			$found = $this->lookUpBuffer($dto->uploadId, $userId);
			$target = $found->isSuccess()
				? $found->getData()['record']['target']
				: ($this->getExpiredBufferError($found)?->getCustomData()['target'] ?? null)
			;
			if (!is_array($target))
			{
				throw $this->createUploadNotFoundRefusal();
			}

			return [
				'mode' => (string)$target['mode'],
				'bindingId' => $this->normalizeTargetId($target['bindingId'] ?? null),
				'pageId' => $this->normalizeTargetId($target['pageId'] ?? null),
			];
		}

		return [
			'mode' => $dto->mode,
			'bindingId' => $this->normalizeTargetId($dto->bindingId),
			'pageId' => null,
		];
	}

	public function handle(PutMarkupFileDto $dto, int $userId): array
	{
		try
		{
			$this->assertMarkupModeSelected();

			if (!$this->isAiSitesMarkupEnabled())
			{
				throw new MarkupRequestRefusedException(
					'AI sites markup tools are disabled.',
					MarkupRequestRefusedException::DISABLED,
				);
			}

			$target = $this->resolveTarget($dto, $userId);
			$found = $dto->uploadId !== '' ? $this->lookUpBuffer($dto->uploadId, $userId) : null;
		}
		finally
		{
			// the next call reads the buffer anew
			$this->lookup = null;
		}

		$buffer = $this->getUploadBuffer();
		$chunkBytes = $buffer->getWriteChunkLimit();

		$record = null;
		if ($found !== null)
		{
			$expiredError = $this->getExpiredBufferError($found);
			if ($expiredError !== null)
			{
				return $this->buildFailedResponse($expiredError, $dto->path, $chunkBytes, $dto->uploadId);
			}
			if (!$found->isSuccess())
			{
				throw $this->createUploadNotFoundRefusal();
			}
			$record = $found->getData()['record'];
		}
		elseif ($target['mode'] === UploadBuffer::MODE_UPDATE)
		{
			if ($target['bindingId'] === null)
			{
				throw new \InvalidArgumentException(self::UPDATE_BINDING_REQUIRED_ERROR);
			}

			$resolved = $this->resolveEditTarget($target['bindingId'], $userId);
			if (!$resolved->isSuccess())
			{
				$refusal = $resolved->getErrors()[0];
				if ((string)$refusal->getCode() === EditTargetResolver::ERROR_ACCESS_DENIED)
				{
					throw new \RuntimeException($refusal->getMessage());
				}

				return $this->buildEditTargetRefusedResponse($refusal, $chunkBytes);
			}
			$target['pageId'] = (int)$resolved->getData()['target']->pageId;
		}

		if ($dto->isInventory())
		{
			return $this->buildInventory($record, $chunkBytes);
		}

		$path = $this->normalizePath($dto->path);
		$pathError = $this->validatePathAgainstBuffer($path, $record);
		if ($pathError !== null)
		{
			return $this->buildFailedResponse($pathError, $path, $chunkBytes, $record['uploadId'] ?? null);
		}
		$source = $this->resolveSource($dto);

		if ($source === self::SOURCE_CONTENT)
		{
			return $this->putWholeFile($dto, $userId, $target, $record, $path, $chunkBytes);
		}

		$record ??= $this->openBuffer($userId, $target);
		$uploadId = (string)$record['uploadId'];

		if ($source === self::SOURCE_DISK)
		{
			$result = $buffer->putDiskFile($uploadId, $userId, $path, $dto->diskFileId);
			if (!$result->isSuccess())
			{
				return $this->buildFailedResponse($result->getErrors()[0], $path, $chunkBytes, $uploadId);
			}

			return $this->buildCompleteAnswer($uploadId, $result->getData(), $chunkBytes);
		}

		// a final call may come without bytes, and a repeated final call finds the file already glued
		$alreadyFinished = $dto->final && $this->isFinished($record, $path, $dto->sha256);
		if (!$dto->final || ($dto->content !== '' && !$alreadyFinished))
		{
			$part = $buffer->putFilePart($uploadId, $userId, $path, (int)$dto->offset, $dto->content);
			if (!$part->isSuccess())
			{
				return $this->buildFailedResponse($part->getErrors()[0], $path, $chunkBytes, $uploadId);
			}
			if (!$dto->final)
			{
				return [
					'uploadId' => $uploadId,
					'path' => $path,
					'complete' => false,
					'received' => (int)$part->getData()['received'],
					'chunkBytes' => $chunkBytes,
				];
			}
		}

		$finished = $buffer->finishFile($uploadId, $userId, $path, $dto->sha256);
		if (!$finished->isSuccess())
		{
			return $this->buildFailedResponse($finished->getErrors()[0], $path, $chunkBytes, $uploadId);
		}

		return $this->buildCompleteAnswer($uploadId, $finished->getData(), $chunkBytes);
	}

	protected function isAiSitesMarkupEnabled(): bool
	{
		return CopilotManager::isAiSitesMarkupEnabled();
	}

	/**
	 * @throws AiSiteModeDisabledException
	 */
	private function assertMarkupModeSelected(): void
	{
		(new AiSiteModeGuard())->assertModeSelected(CopilotManager::AI_SITES_MODE_MARKUP);
	}

	protected function getUploadBuffer(): UploadBuffer
	{
		return new UploadBuffer();
	}

	/**
	 * The page an update buffer is bound to: the single live custom page of the site the binding of
	 * the user is pinned to, which the user may edit.
	 * @return Result Success data: ['target' => SubmissionTarget].
	 */
	protected function resolveEditTarget(int $bindingId, int $userId): Result
	{
		return (new EditTargetResolver())->resolve($bindingId, $userId);
	}

	protected function getValidator(): Validator
	{
		return new Validator();
	}

	private function normalizeTargetId(mixed $id): ?int
	{
		$id = (int)$id;

		return $id > 0 ? $id : null;
	}

	private function putWholeFile(
		PutMarkupFileDto $dto,
		int $userId,
		array $target,
		?array $record,
		string $path,
		int $chunkBytes,
	): array
	{
		$uploadId = $record['uploadId'] ?? null;
		$content = $dto->content;
		if ($dto->encoding === UploadBuffer::ENCODING_BASE64)
		{
			$decoded = base64_decode($content, true);
			if ($decoded === false)
			{
				return $this->buildFailedResponse(
					new Error('Content is not valid base64.', UploadBuffer::ERROR_BASE64_INVALID),
					$path,
					$chunkBytes,
					$uploadId,
				);
			}
			$content = $decoded;
		}

		// unfinished parts of other paths count too: the refusal comes on this file, not later on theirs
		$buffer = $this->getUploadBuffer();
		$fileError = $this->getValidator()->validateFileIntake($path, $content)
			?? $buffer->checkFill($record, $path, strlen($content))
		;
		if ($fileError !== null)
		{
			return $this->buildFailedResponse($fileError, $path, $chunkBytes, $uploadId);
		}

		// an opening call refused for the quota must not leave an empty buffer behind
		if ($record === null && !$buffer->hasDiskQuotaFor(strlen($content)))
		{
			return $this->buildFailedResponse($this->createDiskQuotaError(), $path, $chunkBytes, null);
		}

		$record ??= $this->openBuffer($userId, $target);
		$entry = $buffer->putFile($record['uploadId'], $userId, $path, $content, $dto->encoding);
		if (!$entry)
		{
			// the buffer checks the quota on every write; its refusal is a file answer, not a failure of the tool
			if (!$buffer->hasDiskQuotaFor(strlen($content)))
			{
				return $this->buildFailedResponse(
					$this->createDiskQuotaError(),
					$path,
					$chunkBytes,
					(string)$record['uploadId'],
				);
			}

			throw new \RuntimeException('Failed to store the file into the upload buffer.');
		}

		return $this->buildCompleteAnswer((string)$record['uploadId'], $entry, $chunkBytes);
	}

	private function createDiskQuotaError(): Error
	{
		return new Error(
			'The portal disk quota is exhausted, the file was not stored.',
			UploadBuffer::ERROR_DISK_QUOTA,
		);
	}

	private function openBuffer(int $userId, array $target): array
	{
		// the buffer refuses a target without an identifier
		$targetId = (int)($target['mode'] === UploadBuffer::MODE_UPDATE ? $target['pageId'] : $target['bindingId']);
		$buffer = $this->getUploadBuffer();

		// A new buffer is the first saved effect: the selection is asked again right before it.
		$this->assertMarkupModeSelected();

		$record = $buffer->open($userId, $target['mode'], $targetId);
		if (!$record)
		{
			throw new \RuntimeException(self::UPLOAD_NOT_FOUND_MESSAGE);
		}

		return $record;
	}

	/**
	 * Exactly one source per call: content (whole), content with offset (a part, final=true with
	 * sha256 finishes the file) or diskFileId.
	 */
	private function resolveSource(PutMarkupFileDto $dto): string
	{
		$isPart = $dto->offset !== null || $dto->final;
		$sources = (int)$dto->hasContent + (int)($dto->diskFileId > 0);
		if ($dto->final && !$dto->hasContent && $dto->offset === null && $dto->diskFileId <= 0)
		{
			$sources = 1;
		}

		if ($sources !== 1 || ($isPart && $dto->diskFileId > 0))
		{
			throw new \InvalidArgumentException(
				'Pass exactly one source: content, content with offset, or diskFileId.'
			);
		}
		if ($dto->offset !== null && $dto->offset < 0)
		{
			throw new \InvalidArgumentException('offset must be a non-negative integer.');
		}
		if ($dto->final && ($dto->sha256 === '' || ($dto->offset === null && $dto->hasContent)))
		{
			throw new \InvalidArgumentException(
				'final=true needs sha256 of the whole file and the offset of the part.'
			);
		}

		if ($dto->diskFileId > 0)
		{
			return self::SOURCE_DISK;
		}

		return $isPart ? self::SOURCE_PART : self::SOURCE_CONTENT;
	}

	private function normalizePath(string $path): string
	{
		$path = (string)preg_replace('#/{2,}#', '/', $path);
		while (str_starts_with($path, './'))
		{
			$path = substr($path, 2);
		}

		return $path;
	}

	/**
	 * Paths differing only in letter case are one file on a case-insensitive file system
	 * the package may be unpacked to.
	 */
	private function validatePathAgainstBuffer(string $path, ?array $record): ?Error
	{
		if ($path === '' || !$this->getValidator()->isValidPath($path))
		{
			return new Error('The file path violates the package path rules.', 'MARKUP_BAD_PATH');
		}

		$known = array_merge(array_column($record['files'] ?? [], 'path'), array_keys($record['parts'] ?? []));
		foreach ($known as $knownPath)
		{
			$knownPath = (string)$knownPath;
			if ($knownPath !== $path && mb_strtolower($knownPath) === mb_strtolower($path))
			{
				return new Error(
					'The buffer already holds "' . $knownPath . '": paths may not differ only in letter case.',
					'MARKUP_BAD_PATH',
				);
			}
		}

		return null;
	}

	private function isFinished(array $record, string $path, string $sha256): bool
	{
		if (!empty($record['parts'][$path]))
		{
			return false;
		}

		foreach ($record['files'] as $file)
		{
			if ($file['path'] === $path && $file['hash'] === $sha256)
			{
				return true;
			}
		}

		return false;
	}

	private function buildCompleteAnswer(string $uploadId, array $file, int $chunkBytes): array
	{
		return [
			'uploadId' => $uploadId,
			'path' => (string)$file['path'],
			'complete' => true,
			'fileId' => (int)$file['fileId'],
			'hash' => (string)$file['hash'],
			'size' => (int)$file['size'],
			'chunkBytes' => $chunkBytes,
		];
	}

	/**
	 * What the buffer already holds: "continue" after a break re-sends only the rest.
	 */
	private function buildInventory(array $record, int $chunkBytes): array
	{
		$files = [];
		foreach ($record['files'] as $file)
		{
			$files[] = [
				'path' => (string)$file['path'],
				'size' => (int)$file['size'],
				'sha256' => (string)$file['hash'],
				'complete' => true,
				'received' => (int)$file['size'],
			];
		}
		foreach (($record['parts'] ?? []) as $path => $parts)
		{
			$files[] = [
				'path' => (string)$path,
				'size' => null,
				'sha256' => null,
				'complete' => false,
				'received' => (int)array_sum(array_column($parts, 'size')),
			];
		}

		return [
			'uploadId' => (string)$record['uploadId'],
			'chunkBytes' => $chunkBytes,
			'files' => $files,
		];
	}

	private function createUploadNotFoundRefusal(): MarkupRequestRefusedException
	{
		return new MarkupRequestRefusedException(
			self::UPLOAD_NOT_FOUND_MESSAGE,
			MarkupRequestRefusedException::UPLOAD_NOT_FOUND,
		);
	}

	private function lookUpBuffer(string $uploadId, int $userId): Result
	{
		if ($this->lookup === null || $this->lookup['uploadId'] !== $uploadId || $this->lookup['userId'] !== $userId)
		{
			$this->lookup = [
				'uploadId' => $uploadId,
				'userId' => $userId,
				'found' => $this->getUploadBuffer()->find($uploadId, $userId),
			];
		}

		return $this->lookup['found'];
	}

	private function getExpiredBufferError(Result $found): ?Error
	{
		foreach ($found->getErrors() as $error)
		{
			if ($error->getCode() === UploadBuffer::ERROR_UPLOAD_EXPIRED)
			{
				return $error;
			}
		}

		return null;
	}

	/**
	 * The soft refusal of the edit target: no buffer is opened, so the answer names no file and no buffer,
	 * and the file is not to be sent again.
	 */
	private function buildEditTargetRefusedResponse(Error $refusal, int $chunkBytes): array
	{
		$code = (string)$refusal->getCode();
		$message = match ($code)
		{
			EditTargetResolver::ERROR_NOT_MARKUP_PAGE => 'The page is not a custom markup page. Nothing was stored.',
			EditTargetResolver::ERROR_SITE_NOT_SINGLE_PAGE
				=> 'The site of the binding does not hold a single page. Nothing was stored.',
			default => 'The binding names no site of the user to edit. Nothing was stored.',
		};

		return [
			'status' => 'failed',
			'errors' => [[
				'code' => $code,
				'path' => null,
				'line' => null,
				'details' => $refusal->getMessage(),
				'repairable' => false,
			]],
			'message' => $message,
			'chunkBytes' => $chunkBytes,
		];
	}

	private function buildFailedResponse(Error $error, string $path, int $chunkBytes, ?string $uploadId = null): array
	{
		$code = (string)$error->getCode();
		$answer = [
			'status' => 'failed',
			'errors' => [[
				'code' => $code,
				'path' => $path !== '' ? $path : null,
				// file intake knows no place in the code: only the package static analysis reports a line
				'line' => null,
				'details' => $error->getMessage(),
				'repairable' => in_array($code, self::REPAIRABLE_CODES, true),
			]],
			'message' => 'The file was rejected. Fix it according to the error code and send it again.',
			'chunkBytes' => $chunkBytes,
		];
		if ($uploadId !== null && $uploadId !== '')
		{
			$answer['uploadId'] = $uploadId;
		}

		return $answer;
	}
}
