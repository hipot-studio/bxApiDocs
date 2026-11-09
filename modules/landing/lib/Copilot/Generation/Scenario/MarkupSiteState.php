<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Image\GenerationProgress;
use Bitrix\Landing\Markup\Package;

/**
 * Typed view over the markup scenario slice of the generation state.
 * The data itself lives in the generation DATA storage; one instance serves one generation.
 *
 * The package travels in one form only, whatever its source: the state stores identifiers of
 * the upload buffer files and the contents are re-read from the file store on demand.
 */
class MarkupSiteState
{
	public const PACKAGE_REF_KEY = 'markup_package_ref';
	public const PACKAGE_HASH_KEY = 'markup_package_hash';
	public const UPLOAD_KEY = 'markup_upload';
	public const BLOCK_KEY = 'markup_block';
	public const ERROR_KEY = 'markup_last_error';
	public const REPAIR_KEY = 'markup_repair';
	public const MODERATION_KEY = 'markup_moderation';
	public const IMAGE_PROGRESS_KEY = 'markup_image_progress';
	public const IMAGE_SUMMARY_KEY = 'markup_image_summary';
	public const WARNINGS_KEY = 'markup_warnings';
	/**
	 * Most warnings an answer carries: up to five declared forms give a few warnings each, more come
	 * only from an entry with many markers, and they repeat the kinds of the first ones.
	 */
	public const WARNINGS_LIMIT = 50;

	/** The last error of a job cancelled by the user, whoever records the cancel: the handler or the run. */
	public const CANCELLED_MESSAGE = 'Cancelled by the user.';

	/** @var list<array{code: string, token: string, details: array}>|null */
	private ?array $warnings = null;

	/** @var array<string, true> code and token of the collected warnings */
	private array $warningKeys = [];

	private int $storedWarningCount = 0;

	public function __construct(
		private readonly Generation $generation,
	)
	{
	}

	/**
	 * Stores the package as identifiers of stored files:
	 * {entry: {path, fileId, hash}, files: [{path, fileId, hash}], images, forms?, meta}.
	 * An element of forms may carry the portal binding of its token.
	 */
	public function setPackageReference(array $reference): void
	{
		$this->generation->setData(self::PACKAGE_REF_KEY, $reference);
	}

	public function getPackageReference(): ?array
	{
		$state = $this->generation->getData(self::PACKAGE_REF_KEY);

		return is_array($state) && $state ? $state : null;
	}

	/**
	 * Returns the package reconstructed from the stored file references.
	 * @throws \RuntimeException When a referenced file is no longer readable.
	 */
	public function getPackage(): ?Package
	{
		$reference = $this->getPackageReference();

		return $reference !== null ? $this->reconstructFromReference($reference) : null;
	}

	/**
	 * The package without its asset files: the entry, the image and form declarations and the meta. For the
	 * readers of the page text, so a big asset (a video) is neither read nor hashed for them.
	 * @throws \RuntimeException When the entry file is no longer readable.
	 */
	public function getPackageWithoutAssets(): ?Package
	{
		$reference = $this->getPackageReference();
		if ($reference === null)
		{
			return null;
		}

		unset($reference['files']);

		return $this->reconstructFromReference($reference);
	}

	public function setPackageHash(string $hash): void
	{
		$this->generation->setData(self::PACKAGE_HASH_KEY, ['hash' => $hash]);
	}

	public function getPackageHash(): string
	{
		$state = $this->generation->getData(self::PACKAGE_HASH_KEY);

		return (string)(is_array($state) ? ($state['hash'] ?? '') : '');
	}

	public function setUploadId(string $uploadId): void
	{
		$this->generation->setData(self::UPLOAD_KEY, ['uploadId' => $uploadId]);
	}

	public function getUploadId(): string
	{
		$state = $this->generation->getData(self::UPLOAD_KEY);

		return (string)(is_array($state) ? ($state['uploadId'] ?? '') : '');
	}

	public function setBlockId(int $blockId): void
	{
		$this->generation->setData(self::BLOCK_KEY, ['blockId' => $blockId]);
	}

	public function getBlockId(): int
	{
		$state = $this->generation->getData(self::BLOCK_KEY);

		return (int)(is_array($state) ? ($state['blockId'] ?? 0) : 0);
	}

	private function reconstructFromReference(array $reference): Package
	{
		$package = new Package();

		$entry = (array)($reference['entry'] ?? []);
		$package->setEntry(
			(string)($entry['path'] ?? ''),
			$this->readReferencedContent($entry),
		);
		foreach ((array)($reference['files'] ?? []) as $file)
		{
			if (!is_array($file))
			{
				continue;
			}
			$package->addAsset((string)($file['path'] ?? ''), $this->readReferencedContent($file));
		}
		foreach ((array)($reference['images'] ?? []) as $image)
		{
			if (is_array($image))
			{
				$package->addImage($image);
			}
		}
		foreach ((array)($reference['forms'] ?? []) as $form)
		{
			if (is_array($form))
			{
				$this->restoreForm($package, $form);
			}
		}
		$package->setMeta((array)($reference['meta'] ?? []));

		return $package;
	}

	/**
	 * The reference is written by the portal, so the binding of a token is trusted and restored
	 * after the declaration itself.
	 */
	private function restoreForm(Package $package, array $form): void
	{
		$binding = $form['binding'] ?? null;
		$package->addForm($form);
		if (is_array($binding) && is_string($form['token'] ?? null))
		{
			$package->bindForm(
				$form['token'],
				(int)($binding['formId'] ?? 0),
				(array)($binding['design'] ?? []),
			);
		}
	}

	private function readReferencedContent(array $file): string
	{
		$content = Markup\FileStore::readContent((int)($file['fileId'] ?? 0));
		if (
			$content === null
			|| Markup\FileStore::hash($content) !== (string)($file['hash'] ?? '')
		)
		{
			throw new \RuntimeException(
				'The package file "' . (string)($file['path'] ?? '')
				. '" is no longer available in the upload buffer. Submit the package again.',
			);
		}

		return $content;
	}

	/**
	 * Replaces the package with the given one - the repaired package, so the following steps (site
	 * creation, version apply) materialize the repaired content. The files are put into the upload
	 * buffer of the generation, unchanged bytes are deduplicated by hash and cost nothing, and the
	 * state keeps their identifiers only. A generation without a buffer gets a target-less buffer of
	 * its author.
	 * @throws \RuntimeException When the package cannot be stored into the buffer.
	 */
	public function replacePackage(Package $package): void
	{
		$userId = $this->generation->getAuthorId();
		$uploadId = $this->getUploadId();
		if ($uploadId === '')
		{
			$record = $userId > 0 ? (new Markup\UploadBuffer())->open($userId, Markup\UploadBuffer::MODE_DEV, 0) : null;
			if (!$record)
			{
				throw new \RuntimeException('Failed to open an upload buffer for the markup package.');
			}
			$uploadId = (string)$record['uploadId'];
			$this->setUploadId($uploadId);
		}

		$reference = $this->rebuildReference($package, $uploadId, $userId);
		if ($reference === null)
		{
			throw new \RuntimeException('Failed to store the markup package into the upload buffer.');
		}

		$this->setPackageReference($reference);
	}

	private function rebuildReference(Package $package, string $uploadId, int $userId): ?array
	{
		$buffer = new Markup\UploadBuffer();

		$entry = $package->getEntry();
		$storedEntry = $entry
			? $buffer->putFile($uploadId, $userId, (string)$entry['path'], (string)$entry['content'])
			: null;
		if (!$storedEntry)
		{
			return null;
		}

		$files = [];
		foreach ($package->getAssets() as $asset)
		{
			$stored = $buffer->putFile($uploadId, $userId, (string)$asset['path'], (string)$asset['content']);
			if (!$stored)
			{
				return null;
			}
			$files[] = [
				'path' => (string)$asset['path'],
				'fileId' => (int)$stored['fileId'],
				'hash' => (string)$stored['hash'],
			];
		}

		$reference = [
			'entry' => [
				'path' => (string)$entry['path'],
				'fileId' => (int)$storedEntry['fileId'],
				'hash' => (string)$storedEntry['hash'],
			],
			'files' => $files,
			'images' => $package->getImageDeclaration(),
			'meta' => $package->getMeta(),
		];
		if ($package->getFormDeclaration() !== [])
		{
			$reference['forms'] = $package->getFormDeclaration();
		}

		return $reference;
	}

	public function getRepairIteration(): int
	{
		$state = $this->generation->getData(self::REPAIR_KEY);

		return (int)(is_array($state) ? ($state['iteration'] ?? 0) : 0);
	}

	public function setRepairIteration(int $iteration): void
	{
		$this->generation->setData(self::REPAIR_KEY, [
			'iteration' => $iteration,
			'resolved' => $this->isRepairResolved(),
		]);
	}

	public function isRepairResolved(): bool
	{
		$state = $this->generation->getData(self::REPAIR_KEY);

		return (bool)(is_array($state) ? ($state['resolved'] ?? false) : false);
	}

	public function markRepairResolved(): void
	{
		$this->generation->setData(self::REPAIR_KEY, [
			'iteration' => $this->getRepairIteration(),
			'resolved' => true,
		]);
	}

	/**
	 * Position of the multi-pass topic moderation: the chunk to send next, how many
	 * chunks the moderated text has and the verdict accumulated so far.
	 */
	public function setModerationProgress(int $chunkIndex, int $chunkCount, string $verdict): void
	{
		$this->generation->setData(self::MODERATION_KEY, [
			'chunkIndex' => max(0, $chunkIndex),
			'chunkCount' => max(0, $chunkCount),
			'verdict' => $verdict,
		]);
	}

	public function getModerationChunkIndex(): int
	{
		return (int)($this->getModerationState()['chunkIndex'] ?? 0);
	}

	public function getModerationChunkCount(): int
	{
		return (int)($this->getModerationState()['chunkCount'] ?? 0);
	}

	public function getModerationVerdict(): string
	{
		return (string)($this->getModerationState()['verdict'] ?? '');
	}

	private function getModerationState(): array
	{
		$state = $this->generation->getData(self::MODERATION_KEY);

		return is_array($state) ? $state : [];
	}

	/**
	 * Course of the image generation step (GenerationProgress::toState()).
	 */
	public function setImageProgress(array $progress): void
	{
		$this->generation->setData(self::IMAGE_PROGRESS_KEY, $progress);
	}

	public function getImageProgress(): array
	{
		$state = $this->generation->getData(self::IMAGE_PROGRESS_KEY);

		return is_array($state) ? $state : [];
	}

	/**
	 * Outcomes of the generated pictures by token, for the intake of the package.
	 * @return array<string, array{status: string, aiFileId?: int, reason?: string}>
	 */
	public function getImageOutcomes(): array
	{
		return GenerationProgress::fromState($this->getImageProgress())->getOutcomes();
	}

	/**
	 * Summary of the images of the applied package: {total, generated, reused, failed: [{token, reason}]}.
	 */
	public function setImageSummary(array $summary): void
	{
		$this->generation->setData(self::IMAGE_SUMMARY_KEY, $summary);
	}

	public function getImageSummary(): ?array
	{
		$state = $this->generation->getData(self::IMAGE_SUMMARY_KEY);

		return is_array($state) && $state ? $state : null;
	}

	/**
	 * Collects a warning for the answer of the finished generation. A repeated pair of code and
	 * token is kept once: a step may run again after a retry. Warnings beyond WARNINGS_LIMIT are dropped.
	 * @param array{code: string, token: string, details: array} $warning
	 */
	public function addWarning(array $warning): void
	{
		$this->loadWarnings();
		$code = (string)$warning['code'];
		$token = (string)$warning['token'];
		$key = $code . "\n" . $token;
		if (isset($this->warningKeys[$key]) || count($this->warnings) >= self::WARNINGS_LIMIT)
		{
			return;
		}

		$this->warnings[] = [
			'code' => $code,
			'token' => $token,
			'details' => (array)($warning['details'] ?? []),
		];
		$this->warningKeys[$key] = true;
		$this->storedWarningCount = count($this->warnings);
		$this->generation->setData(self::WARNINGS_KEY, ['items' => $this->warnings]);
	}

	/**
	 * Reads the stored warnings once, and again only when another state of the generation has changed them.
	 */
	private function loadWarnings(): void
	{
		$count = count($this->readStoredWarnings());
		if ($this->warnings !== null && $count === $this->storedWarningCount)
		{
			return;
		}

		$this->warnings = $this->getWarnings();
		$this->warningKeys = [];
		foreach ($this->warnings as $known)
		{
			$this->warningKeys[($known['code'] ?? '') . "\n" . ($known['token'] ?? '')] = true;
		}
		$this->storedWarningCount = $count;
	}

	private function readStoredWarnings(): array
	{
		$state = $this->generation->getData(self::WARNINGS_KEY);
		$items = is_array($state) ? ($state['items'] ?? []) : [];

		return is_array($items) ? $items : [];
	}

	/**
	 * @return list<array{code: string, token: string, details: array}>
	 */
	public function getWarnings(): array
	{
		return array_values(array_filter($this->readStoredWarnings(), 'is_array'));
	}

	public function setLastError(string $message): void
	{
		$this->generation->setData(self::ERROR_KEY, ['message' => $message]);
	}

	public function getLastError(): string
	{
		$state = $this->generation->getData(self::ERROR_KEY);

		return (string)(is_array($state) ? ($state['message'] ?? '') : '');
	}
}
