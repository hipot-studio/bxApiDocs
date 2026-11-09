<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Submission;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\Intake;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\MarkupPage;
use Bitrix\Landing\Markup\Package;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Markup\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Intake of a markup package, common to every source: the package is assembled from the upload
 * buffer, the inline parts and the manifest of the target, every inline part passes the intake checks
 * of the buffer and is materialized into it - the generation state carries file identifiers only - and
 * fingerprinted, so a repeated submission maps to the live job instead of a second one.
 *
 * Inline content is accepted base64-encoded only: a model passing a document as a plain JSON string
 * damages it on the way (quotes, escapes, security filters of the portal), and a refusal before the
 * generation is cheaper than a broken site. Acceptance refusals come back as errors of the result,
 * nothing is created for them.
 */
class PackageSubmissionService
{
	public const ENCODING_BASE64 = 'base64';

	public const ERROR_INLINE_FORBIDDEN = 'MARKUP_INLINE_FORBIDDEN';
	public const ERROR_BASE64_INVALID = UploadBuffer::ERROR_BASE64_INVALID;
	public const ERROR_UPLOAD_EXPIRED = UploadBuffer::ERROR_UPLOAD_EXPIRED;
	public const ERROR_NO_ENTRY = 'MARKUP_NO_ENTRY';
	public const ERROR_BAD_FILE_REF = 'MARKUP_BAD_FILE_REF';

	// acceptance refusals the source fixes by passing the named file again; the package is then resubmitted
	private const RESENDABLE_CODES = [self::ERROR_INLINE_FORBIDDEN, self::ERROR_BASE64_INVALID];

	private const UPLOAD_NOT_FOUND_MESSAGE = 'Upload buffer was not found or belongs to another user.';

	private readonly UploadBuffer $buffer;
	private readonly \Closure $contentReader;

	/**
	 * @param \Closure(int): ?string|null $contentReader Reads the bytes of a stored file.
	 */
	public function __construct(?UploadBuffer $buffer = null, ?\Closure $contentReader = null)
	{
		$this->buffer = $buffer ?? new UploadBuffer();
		$this->contentReader = $contentReader ?? static fn(int $fileId): ?string => FileStore::readContent($fileId);
	}

	public static function isResendable(string $code): bool
	{
		return in_array($code, self::RESENDABLE_CODES, true);
	}

	/**
	 * The package in the shape assemble() takes, every part inline and base64-encoded.
	 */
	public static function describeInline(Package $package): array
	{
		$entry = $package->getEntry() ?? ['path' => '', 'content' => ''];

		return [
			'entry' => [
				'path' => (string)$entry['path'],
				'content' => base64_encode((string)$entry['content']),
				'encoding' => self::ENCODING_BASE64,
			],
			'files' => array_map(
				static fn(array $asset): array => [
					'path' => (string)$asset['path'],
					'content' => base64_encode((string)$asset['content']),
					'encoding' => self::ENCODING_BASE64,
				],
				$package->getAssets(),
			),
			'images' => $package->getImageDeclaration(),
			'forms' => $package->getFormDeclaration(),
			'meta' => $package->getMeta(),
		];
	}

	/**
	 * Assembles the package. A file of the buffer becomes a part of the package automatically, an
	 * inline file of the same path overrides it; an unchanged file of the target page arrives as
	 * {path, fileId, hash} and costs zero new bytes.
	 *
	 * @param array{
	 *     entry?: array{path?: string, content?: string, encoding?: string, fileId?: int, hash?: string},
	 *     files?: list<array{path?: string, content?: string, encoding?: string, fileId?: int, hash?: string}>,
	 *     images?: list<array>,
	 *     forms?: list<array>,
	 *     meta?: array
	 * } $package
	 * @return Result Success data: ['package' => Package, 'reference' => array, 'uploadId' => string,
	 *     'fingerprint' => string].
	 */
	public function assemble(
		PackageSource $source,
		int $userId,
		SubmissionTarget $target,
		array $package,
		?string $uploadId,
	): Result
	{
		$this->assertSourceMatchesTarget($source, $target);
		$this->assertChatCreatesNoSecondSite($target, $userId);

		$result = new Result();
		$package = $this->normalizePackage($package);
		$entrySpec = $package['entry'];
		if ($entrySpec['path'] === '')
		{
			return $result->addError(new Error('The package entry document is required.', self::ERROR_NO_ENTRY));
		}

		$decoded = $this->decodeInlineParts($package);
		if (!$decoded->isSuccess())
		{
			return $decoded;
		}
		$package = $decoded->getData()['package'];
		$entrySpec = $package['entry'];

		$record = null;
		$uploadId = trim((string)$uploadId);
		if ($uploadId !== '')
		{
			$found = $this->findBuffer($uploadId, $userId, $target);
			if (!$found->isSuccess())
			{
				return $found;
			}
			$record = $found->getData()['record'];
			$this->buffer->touch($uploadId);
		}

		// path => ['content' => string, 'fileId' => int, 'hash' => string]
		$map = [];
		foreach (($record['files'] ?? []) as $file)
		{
			$content = $this->readStoredContent((int)$file['fileId']);
			if ($content === null || FileStore::hash($content) !== (string)$file['hash'])
			{
				return $result->addError(new Error(
					'The uploaded file is no longer available in the buffer. Upload it and submit again.',
					self::ERROR_UPLOAD_EXPIRED,
					['path' => (string)$file['path']],
				));
			}
			$map[(string)$file['path']] = [
				'content' => $content,
				'fileId' => (int)$file['fileId'],
				'hash' => (string)$file['hash'],
			];
		}

		foreach ($package['files'] as $file)
		{
			$path = $file['path'];
			if (array_key_exists('content', $file))
			{
				$map[$path] = ['content' => $file['content'], 'fileId' => 0, 'hash' => ''];
				continue;
			}

			$known = $map[$path] ?? null;
			if (!$known || $known['fileId'] !== $file['fileId'] || $known['hash'] !== $file['hash'])
			{
				$known = $this->resolveExternalFileReference($target, $file);
			}
			if (!$known)
			{
				return $result->addError(new Error(
					'The referenced file was not found among the stored files of this submission.',
					self::ERROR_BAD_FILE_REF,
					['path' => $path],
				));
			}
			$map[$path] = $known;
		}

		$entryPath = $entrySpec['path'];
		if (array_key_exists('content', $entrySpec))
		{
			$entry = ['content' => $entrySpec['content'], 'fileId' => 0, 'hash' => ''];
		}
		elseif (
			isset($map[$entryPath])
			&& ($entrySpec['fileId'] <= 0 || $map[$entryPath]['fileId'] === $entrySpec['fileId'])
		)
		{
			$entry = $map[$entryPath];
		}
		else
		{
			$entry = $this->resolveExternalFileReference($target, $entrySpec);
			if (!$entry)
			{
				return $result->addError(new Error(
					'The package entry document was not found among the stored files of this submission.',
					self::ERROR_BAD_FILE_REF,
					['path' => $entryPath],
				));
			}
		}
		unset($map[$entryPath]);

		// materialize inline parts, so the state carries identifiers only
		$needsBuffer = $entry['fileId'] <= 0
			|| array_filter($map, static fn(array $file): bool => $file['fileId'] <= 0) !== [];
		$inlineError = $needsBuffer ? $this->checkInlineParts($entryPath, $entry, $map) : null;
		if ($inlineError !== null)
		{
			return $result->addError($inlineError);
		}
		if ($needsBuffer && !$record)
		{
			$record = $this->buffer->open($userId, $target->mode, $target->getBufferTargetId());
			if (!$record)
			{
				throw new \RuntimeException('Failed to open the upload buffer for the submission.');
			}
		}
		if ($entry['fileId'] <= 0)
		{
			$entry = $this->materializeIntoBuffer($record, $userId, $entryPath, $entry['content']);
			if ($entry instanceof Error)
			{
				return $result->addError($entry);
			}
		}
		foreach ($map as $path => $file)
		{
			if ($file['fileId'] <= 0)
			{
				$map[$path] = $this->materializeIntoBuffer($record, $userId, $path, $file['content']);
				if ($map[$path] instanceof Error)
				{
					return $result->addError($map[$path]);
				}
			}
		}

		$assembled = new Package();
		$assembled->setEntry($entryPath, $entry['content']);
		$referenceFiles = [];
		foreach ($map as $path => $file)
		{
			$assembled->addAsset($path, $file['content']);
			$referenceFiles[] = ['path' => $path, 'fileId' => $file['fileId'], 'hash' => $file['hash']];
		}
		foreach ($package['images'] as $image)
		{
			$assembled->addImage($image);
		}
		foreach ($package['forms'] as $form)
		{
			$assembled->addForm($form);
		}
		if ($package['meta'] !== [])
		{
			$assembled->setMeta($package['meta']);
		}

		$reference = [
			'entry' => ['path' => $entryPath, 'fileId' => $entry['fileId'], 'hash' => $entry['hash']],
			'files' => $referenceFiles,
			'images' => $package['images'],
			'meta' => $package['meta'],
		];
		if ($assembled->getFormDeclaration() !== [])
		{
			$reference['forms'] = $assembled->getFormDeclaration();
		}

		return $result->setData([
			'package' => $assembled,
			'reference' => $reference,
			'uploadId' => (string)($record['uploadId'] ?? ''),
			'fingerprint' => $this->buildFingerprint($reference, $assembled),
		]);
	}

	/**
	 * Writes the assembled transport into the generation state: buffer identifiers, the
	 * fingerprint and the buffer the generation consumes.
	 */
	public function storeTransport(Generation $generation, array $reference, string $fingerprint, string $uploadId): void
	{
		$state = new MarkupSiteState($generation);
		$state->setPackageReference($reference);
		$state->setPackageHash($fingerprint);
		if ($uploadId !== '')
		{
			$state->setUploadId($uploadId);
		}
	}

	/**
	 * Idempotence of the submission: the live job of the same author and the same package
	 * fingerprint. A finished job and a job whose failure is written are never live - a
	 * resubmission after them starts a new generation.
	 */
	public function findLiveSubmission(SubmissionTarget $target, int $userId, string $fingerprint): ?Generation
	{
		if ($fingerprint === '')
		{
			return null;
		}

		$generation = $target->isUpdate()
			? $this->findActiveUpdateGeneration($target->pageId, $target->siteId)
			: $this->findLiveBindingGeneration($target->bindingId, $userId)
		;
		if (
			$generation === null
			|| $generation->getAuthorId() !== $userId
			|| (new MarkupSiteState($generation))->getPackageHash() !== $fingerprint
		)
		{
			return null;
		}

		return $generation;
	}

	/**
	 * The live markup creation the binding is reserved for: the winner of concurrent submissions
	 * into one chat.
	 */
	public function findLiveBindingGeneration(int $bindingId, int $userId): ?Generation
	{
		$binding = $this->getAiSiteBindingService()->getByIdForUser($bindingId, $userId);
		$generationId = (int)($binding['GENERATION_ID'] ?? 0);
		if ($generationId <= 0)
		{
			return null;
		}

		$generation = $this->initGenerationById($generationId);
		if (
			$generation === null
			|| $generation->getScenario()::class !== CreateMarkupSite::class
			|| !$this->isLive($generation)
		)
		{
			return null;
		}

		return $generation;
	}

	/**
	 * The "one active submission per page" invariant, held by database state: an unfinished
	 * ChangeMarkupSite generation of the target page without a written failure.
	 */
	public function findActiveUpdateGeneration(int $pageId, int $siteId, int $beforeGenerationId = 0): ?Generation
	{
		$query = GenerationsTable::query()
			->setSelect(['ID'])
			->where('SCENARIO', '=', ChangeMarkupSite::class)
			->where('SITE_ID', '=', $siteId)
			->whereNull('DATE_FINISHED')
			->setOrder(['ID' => 'ASC'])
		;
		if ($beforeGenerationId > 0)
		{
			$query->where('ID', '<', $beforeGenerationId);
		}

		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$generation = $this->initGenerationById((int)$row['ID']);
			if (
				$generation === null
				|| (int)($generation->getSiteData()->getLandingId() ?? 0) !== $pageId
				|| !$this->isLive($generation)
			)
			{
				continue;
			}

			return $generation;
		}

		return null;
	}

	/**
	 * The update target: a page the user may edit (the edit mode checks the rights) that is a custom
	 * markup page. Null for a page that is not custom; a carrier without a saved manifest yet is custom
	 * and answers an empty manifest.
	 * @throws \RuntimeException When the page does not exist or is not accessible.
	 */
	public function resolveUpdateTarget(int $pageId): ?SubmissionTarget
	{
		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($pageId);
			if (!$landing->exist())
			{
				throw new \RuntimeException('The target page was not found or access is denied.');
			}

			if (!MarkupPage::isMarkupLanding($pageId))
			{
				return null;
			}

			$block = Intake::findCustomBlock($landing);
			$manifest = $block ? Manifest::fromJson($block->getMarkupManifest()) : null;

			return SubmissionTarget::update($pageId, (int)$landing->getSiteId(), $manifest ?? new Manifest());
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}
	}

	protected function initGenerationById(int $generationId): ?Generation
	{
		$generation = new Generation();

		return $generation->initById($generationId) ? $generation : null;
	}

	protected function getAiSiteBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	private function isLive(Generation $generation): bool
	{
		return !$generation->isFinished() && !$generation->isErrorPersisted();
	}

	private function assertSourceMatchesTarget(PackageSource $source, SubmissionTarget $target): void
	{
		if (($source === PackageSource::Dev) !== $target->isDev())
		{
			throw new \InvalidArgumentException('The package source does not match the submission target.');
		}
	}

	/**
	 * One chat - one site: a chat pinned to the site it has created takes changes of that site only, a
	 * creation there is refused before anything is assembled - the buffer of the chat is gone by then.
	 * Any other state of the binding is judged by the submission gates.
	 */
	private function assertChatCreatesNoSecondSite(SubmissionTarget $target, int $userId): void
	{
		if ($target->isUpdate() || $target->isDev() || $target->bindingId <= 0)
		{
			return;
		}

		$binding = $this->getAiSiteBindingService()->getByIdForUser($target->bindingId, $userId);
		if ((int)($binding['SITE_ID'] ?? 0) > 0)
		{
			throw new \RuntimeException(AiSiteChatBindingContract::NEW_CHAT_REQUIRED_ERROR);
		}
	}

	/**
	 * The own buffer of the target, touched. An expired buffer and a buffer already dropped after a
	 * generation of the user consumed it answer MARKUP_UPLOAD_EXPIRED: the skill opens a new buffer.
	 * A consumed buffer names the generation in the jobId of the error: its status tells the outcome.
	 */
	private function findBuffer(string $uploadId, int $userId, SubmissionTarget $target): Result
	{
		$found = $this->buffer->find($uploadId, $userId);
		if (!$found->isSuccess())
		{
			// find() answers ERROR_UPLOAD_EXPIRED for an own idle buffer and ERROR_UPLOAD_NOT_FOUND otherwise
			$expired = $found->getErrorCollection()->getErrorByCode(self::ERROR_UPLOAD_EXPIRED) !== null;

			$usedByJobId = $this->buffer->findUsedByGenerationId($uploadId, $userId);
			if ($usedByJobId !== null)
			{
				return (new Result())->addError(new Error(
					'The upload buffer was used by the submission jobId ' . $usedByJobId
					. ' and released. Read its outcome with get_markup_job_status by this jobId '
					. 'before opening a new buffer.',
					self::ERROR_UPLOAD_EXPIRED,
					['jobId' => (string)$usedByJobId],
				));
			}

			if ($expired)
			{
				return (new Result())->addError(new Error(
					'The upload buffer expired without activity and its files are gone. '
					. 'Open a new buffer and upload the package again.',
					self::ERROR_UPLOAD_EXPIRED,
				));
			}

			throw new \RuntimeException(self::UPLOAD_NOT_FOUND_MESSAGE);
		}

		$record = $found->getData()['record'];
		$recordTargetId = (int)($record['target']['pageId'] ?? $record['target']['bindingId'] ?? 0);
		if ((string)$record['target']['mode'] !== $target->mode || $recordTargetId !== $target->getBufferTargetId())
		{
			throw new \RuntimeException('The upload buffer belongs to another submission target.');
		}

		return $found;
	}

	/**
	 * @return array{
	 *     entry: array{path: string, content?: string, encoding?: string, fileId: int, hash: string},
	 *     files: list<array{path: string, content?: string, encoding?: string, fileId: int, hash: string}>,
	 *     images: list<array>,
	 *     forms: list<array>,
	 *     meta: array
	 * }
	 */
	private function normalizePackage(array $package): array
	{
		$normalizePart = static function (mixed $part): ?array
		{
			if (!is_array($part))
			{
				return null;
			}

			$normalized = [
				'path' => is_scalar($part['path'] ?? null) ? trim((string)$part['path']) : '',
				'fileId' => (int)($part['fileId'] ?? 0),
				'hash' => is_scalar($part['hash'] ?? null) ? trim((string)$part['hash']) : '',
			];
			if (is_scalar($part['content'] ?? null))
			{
				$normalized['content'] = (string)$part['content'];
				$normalized['encoding'] = is_scalar($part['encoding'] ?? null)
					? mb_strtolower(trim((string)$part['encoding']))
					: ''
				;
			}

			return $normalized;
		};

		$files = [];
		foreach ((array)($package['files'] ?? []) as $file)
		{
			$file = $normalizePart($file);
			if ($file !== null && $file['path'] !== '')
			{
				$files[] = $file;
			}
		}

		return [
			'entry' => $normalizePart($package['entry'] ?? null) ?? ['path' => '', 'fileId' => 0, 'hash' => ''],
			'files' => $files,
			'images' => array_values(array_filter((array)($package['images'] ?? []), 'is_array')),
			'forms' => array_values(array_filter((array)($package['forms'] ?? []), 'is_array')),
			'meta' => (array)($package['meta'] ?? []),
		];
	}

	/**
	 * Every inline part must be base64: a part without the encoding is refused as a whole class,
	 * an undecodable one names its file. Nothing is stored before all parts pass.
	 * @return Result Success data: ['package' => array] with the decoded bytes in place of content.
	 */
	private function decodeInlineParts(array $package): Result
	{
		$result = new Result();

		$decode = static function (array $part) use ($result): array
		{
			if (!array_key_exists('content', $part))
			{
				return $part;
			}

			if ($part['encoding'] !== self::ENCODING_BASE64)
			{
				$result->addError(new Error(
					'Inline content is accepted only with encoding "base64". Pass the file through put_markup_file '
					. 'or base64-encode it and submit again.',
					self::ERROR_INLINE_FORBIDDEN,
					['path' => $part['path']],
				));

				return $part;
			}

			$bytes = base64_decode($part['content'], true);
			if ($bytes === false)
			{
				$result->addError(new Error(
					'Content is not valid base64. Re-encode the file and pass it again.',
					self::ERROR_BASE64_INVALID,
					['path' => $part['path']],
				));

				return $part;
			}
			$part['content'] = $bytes;

			return $part;
		};

		$package['entry'] = $decode($package['entry']);
		$package['files'] = array_map($decode, $package['files']);

		return $result->isSuccess() ? $result->setData(['package' => $package]) : $result;
	}

	/**
	 * The intake checks of the buffer for the inline parts, run before any of them is written: path, size
	 * and type of every inline file and the package limits over the whole assembled package. Html is judged
	 * by the package validation: only there the roles of files are set. The disk quota is checked by the
	 * buffer on every write.
	 * @param array<string, array{content: string, fileId: int, hash: string}> $assets
	 */
	private function checkInlineParts(string $entryPath, array $entry, array $assets): ?Error
	{
		$validator = new Validator();

		if ($entry['fileId'] <= 0)
		{
			if (!$validator->isValidPath($entryPath))
			{
				return new Error(
					'The file path violates the package path rules.',
					'MARKUP_BAD_PATH',
					['path' => $entryPath],
				);
			}
			if (strlen($entry['content']) > Validator::LIMIT_ENTRY_SIZE)
			{
				return new Error(
					'The entry document exceeds the entry size limit.',
					'MARKUP_ENTRY_TOO_BIG',
					['path' => $entryPath],
				);
			}
		}

		$sizes = [];
		foreach ($assets as $path => $file)
		{
			$path = (string)$path;
			$sizes[] = ['path' => $path, 'size' => strlen($file['content'])];
			if ($file['fileId'] > 0)
			{
				continue;
			}

			$error = $validator->validateFileIntake($path, $file['content']);
			if ($error !== null)
			{
				return new Error($error->getMessage(), $error->getCode(), ['path' => $path]);
			}
		}

		$limitError = $validator->validateBufferFill($sizes, $entryPath, strlen($entry['content']));
		if ($limitError !== null)
		{
			// the package limits belong to no single file, the entry size belongs to the entry
			$path = $limitError->getCode() === 'MARKUP_ENTRY_TOO_BIG' ? $entryPath : null;

			return new Error($limitError->getMessage(), $limitError->getCode(), ['path' => $path]);
		}

		return null;
	}

	/**
	 * Resolution point for file references outside of the upload buffer: the update target resolves
	 * unchanged files against the manifest of its page. The create target has no external source.
	 * @return array{content: string, fileId: int, hash: string}|null
	 */
	private function resolveExternalFileReference(SubmissionTarget $target, array $file): ?array
	{
		if ($target->manifest === null)
		{
			return null;
		}

		$fileId = (int)($file['fileId'] ?? 0);
		$hash = (string)($file['hash'] ?? '');
		if ($fileId <= 0 || $hash === '' || !$target->manifest->containsFileId($fileId))
		{
			return null;
		}

		$known = $target->manifest->findByHash($hash);
		if (!$known || (int)$known['fileId'] !== $fileId)
		{
			return null;
		}

		$content = $this->readStoredContent($fileId);
		if ($content === null || FileStore::hash($content) !== $hash)
		{
			return null;
		}

		return ['content' => $content, 'fileId' => $fileId, 'hash' => $hash];
	}

	/**
	 * @return array{content: string, fileId: int, hash: string}|Error The stored file or the quota refusal.
	 */
	private function materializeIntoBuffer(array $record, int $userId, string $path, string $content): array|Error
	{
		$stored = $this->buffer->putFile(
			(string)$record['uploadId'],
			$userId,
			$path,
			$content,
			UploadBuffer::ENCODING_BASE64,
		);
		if (!$stored)
		{
			if (!$this->buffer->hasDiskQuotaFor(strlen($content)))
			{
				return new Error(
					'The portal disk quota is exhausted, the package file was not stored.',
					UploadBuffer::ERROR_DISK_QUOTA,
					['path' => $path],
				);
			}

			throw new \RuntimeException('Failed to store a package file into the upload buffer.');
		}

		return ['content' => $content, 'fileId' => (int)$stored['fileId'], 'hash' => (string)$stored['hash']];
	}

	/**
	 * A stable fingerprint of the submitted package: the same buffer or byte-identical content
	 * re-submitted twice maps to the same running job. The same markup with another form
	 * declaration is another package. A package without forms keeps the fingerprint it had
	 * before forms were declared, so a live job is still found after an update of the portal.
	 */
	private function buildFingerprint(array $reference, Package $package): string
	{
		$files = array_map(
			static fn(array $file): array => ['path' => $file['path'], 'hash' => $file['hash']],
			$reference['files'],
		);
		usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

		$fingerprint = [
			'entry' => ['path' => $reference['entry']['path'], 'hash' => $reference['entry']['hash']],
			'files' => $files,
			'images' => $package->getImageDeclaration(),
			'meta' => $package->getMeta(),
		];
		if ($package->getFormDeclaration() !== [])
		{
			$fingerprint['forms'] = $package->getFormDeclaration();
		}

		return hash('sha256', (string)json_encode($fingerprint));
	}

	private function readStoredContent(int $fileId): ?string
	{
		return ($this->contentReader)($fileId);
	}
}
