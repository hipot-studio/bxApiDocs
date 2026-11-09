<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Markup\Package;

readonly class SubmitMarkupPackageDto
{
	public const MODE_CREATE = 'create';
	public const MODE_UPDATE = 'update';

	public const ENCODING_BASE64 = 'base64';

	private const META_FIELDS = ['title', 'pageTitle', 'description', 'ogTitle', 'ogDescription', 'ogImage'];
	private const IMAGE_FIELDS = ['token', 'kind', 'prompt', 'aspect', 'url', 'alt'];
	// answered by the read channel only; the binding of a token is kept by the portal
	private const FORM_SERVICE_FIELDS = ['formId', 'formName', 'binding'];

	private function __construct(
		public string $mode = '',
		public int $bindingId = 0,
		public string $uploadId = '',
		public string $entryPath = '',
		public string $entryContent = '',
		public string $entryEncoding = '',
		public int $entryFileId = 0,
		public string $entryHash = '',
		/** @var list<array{path: string, content?: string, encoding?: string, fileId?: int, hash?: string}> */
		public array $files = [],
		/** @var list<array<string, string|bool>> */
		public array $images = [],
		/** @var array<string, string> */
		public array $meta = [],
		/** @var list<array> */
		public array $forms = [],
	)
	{
	}

	public static function fromArray(array $props): self
	{
		$package = is_array($props['package'] ?? null) ? $props['package'] : [];
		$entry = is_array($package['entry'] ?? null) ? $package['entry'] : [];

		return new self(
			mode: is_string($props['mode'] ?? null) ? trim($props['mode']) : '',
			bindingId: self::normalizePositiveInteger($props[AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD] ?? null),
			uploadId: is_string($props['uploadId'] ?? null) ? trim($props['uploadId']) : '',
			entryPath: is_scalar($entry['path'] ?? null) ? trim((string)$entry['path']) : '',
			entryContent: is_scalar($entry['content'] ?? null) ? (string)$entry['content'] : '',
			entryEncoding: self::normalizeEncoding($entry['encoding'] ?? null),
			entryFileId: self::normalizePositiveInteger($entry['fileId'] ?? null),
			entryHash: is_scalar($entry['hash'] ?? null) ? trim((string)$entry['hash']) : '',
			files: self::normalizeFiles($package['files'] ?? null),
			images: self::normalizeImages($package['images'] ?? null),
			meta: self::normalizeMeta($package['meta'] ?? null),
			forms: self::normalizeForms($package['forms'] ?? null),
		);
	}

	/**
	 * The package in the shape the intake service assembles: inline parts keep their content as
	 * passed, with the encoding; the service refuses content that is not base64.
	 */
	public function getPackageArray(): array
	{
		$entry = [
			'path' => $this->entryPath,
			'fileId' => $this->entryFileId,
			'hash' => $this->entryHash,
		];
		if ($this->entryContent !== '')
		{
			$entry['content'] = $this->entryContent;
			$entry['encoding'] = $this->entryEncoding;
		}

		return [
			'entry' => $entry,
			'files' => $this->files,
			'images' => $this->images,
			'forms' => $this->forms,
			'meta' => $this->meta,
		];
	}

	/**
	 * Builds the package from the inline parts only, base64 content decoded. File references
	 * (fileId + hash), the upload buffer and the refusal of content without base64 belong to
	 * the intake service.
	 */
	public function buildPackage(): Package
	{
		$package = new Package();
		$package->setEntry($this->entryPath, self::decodeContent($this->entryContent, $this->entryEncoding));
		foreach ($this->files as $file)
		{
			if (array_key_exists('content', $file))
			{
				$package->addAsset($file['path'], self::decodeContent($file['content'], $file['encoding'] ?? ''));
			}
		}
		foreach ($this->images as $image)
		{
			$package->addImage($image);
		}
		foreach ($this->forms as $form)
		{
			$package->addForm($form);
		}
		if ($this->meta !== [])
		{
			$package->setMeta($this->meta);
		}

		return $package;
	}

	/**
	 * @return list<array{path: string, content?: string, fileId?: int, hash?: string}>
	 */
	private static function normalizeFiles(mixed $files): array
	{
		if (!is_array($files))
		{
			return [];
		}

		$normalized = [];
		foreach ($files as $file)
		{
			if (!is_array($file) || !is_scalar($file['path'] ?? null))
			{
				continue;
			}

			$path = trim((string)$file['path']);
			if (is_scalar($file['content'] ?? null))
			{
				$item = [
					'path' => $path,
					'content' => (string)$file['content'],
				];
				$encoding = self::normalizeEncoding($file['encoding'] ?? null);
				if ($encoding !== '')
				{
					$item['encoding'] = $encoding;
				}
				$normalized[] = $item;
				continue;
			}

			$fileId = self::normalizePositiveInteger($file['fileId'] ?? null);
			$hash = is_scalar($file['hash'] ?? null) ? trim((string)$file['hash']) : '';
			if ($fileId > 0 && $hash !== '')
			{
				$normalized[] = [
					'path' => $path,
					'fileId' => $fileId,
					'hash' => $hash,
				];
			}
		}

		return $normalized;
	}

	/**
	 * A declaration as read from the package comes back as it is: its outcome and reason are the portal's
	 * own and are dropped, the one-off regenerate flag is kept for a generated picture only.
	 * @return list<array<string, string|bool>>
	 */
	private static function normalizeImages(mixed $images): array
	{
		if (!is_array($images))
		{
			return [];
		}

		$normalized = [];
		foreach ($images as $image)
		{
			if (!is_array($image))
			{
				continue;
			}

			$item = [];
			foreach (self::IMAGE_FIELDS as $field)
			{
				if (is_scalar($image[$field] ?? null) && (string)$image[$field] !== '')
				{
					$item[$field] = trim((string)$image[$field]);
				}
			}
			if (
				($item['kind'] ?? '') === Package::IMAGE_KIND_GENERATE
				&& filter_var($image['regenerate'] ?? false, FILTER_VALIDATE_BOOLEAN)
			)
			{
				$item['regenerate'] = true;
			}

			if ($item !== [])
			{
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Elements keep their structure as passed: the package validation judges the declaration and
	 * refuses a broken one with MARKUP_BAD_FORM. Only the service fields of the portal are cut off.
	 * @return list<array>
	 */
	private static function normalizeForms(mixed $forms): array
	{
		if (!is_array($forms))
		{
			return [];
		}

		$normalized = [];
		foreach ($forms as $form)
		{
			if (is_array($form))
			{
				$normalized[] = array_diff_key($form, array_flip(self::FORM_SERVICE_FIELDS));
			}
		}

		return $normalized;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalizeMeta(mixed $meta): array
	{
		if (!is_array($meta))
		{
			return [];
		}

		$normalized = [];
		foreach (self::META_FIELDS as $field)
		{
			if (is_scalar($meta[$field] ?? null) && trim((string)$meta[$field]) !== '')
			{
				$normalized[$field] = trim((string)$meta[$field]);
			}
		}

		return $normalized;
	}

	private static function normalizeEncoding(mixed $value): string
	{
		return is_scalar($value) ? mb_strtolower(trim((string)$value)) : '';
	}

	private static function decodeContent(string $content, string $encoding): string
	{
		if ($encoding !== self::ENCODING_BASE64)
		{
			return $content;
		}

		$decoded = base64_decode($content, true);

		return $decoded === false ? '' : $decoded;
	}

	private static function normalizePositiveInteger(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}
}
