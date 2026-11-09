<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Crm\WebForm;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Subtype;
use Bitrix\Main\Loader;

/**
 * Builds the stored package version back into the contract form (the read channel),
 * so the agent sees the code in the exact form it is obliged to return. Text files travel
 * by content; binaries - by identifier and hash only (the agent references them back as
 * {path, fileId, hash}, zero bytes both ways).
 *
 * The read tool goes file by file: inventory() lists the version without contents, readPart()
 * returns one part of one file, so a big package never has to fit a single answer.
 *
 * Text files of the current manifest version are stored in the contract form already. A first
 * version manifest (older markup sites) has portal file urls substituted into its texts: they
 * are substituted back to the package paths and bx-asset:// tokens by the manifest map.
 *
 * Known limitation: a degraded image token of a first version manifest (a failed download
 * replaced by a donor or a placeholder) left no manifest trace, so its substituted url stays as is.
 *
 * The CRM forms of the version are listed by token with the bound form and its design in the form
 * of the declaration, so the agent can return it as is. Fields are not listed: they are changed
 * by the form tools, never by the package. The name of a form comes from the CRM to a reader
 * of CRM forms only, the same right the form tools check; for others it is empty.
 */
class PackageExporter
{
	/**
	 * Kind of the entry document in the inventory; package files have the kind of their path.
	 */
	public const KIND_ENTRY = 'entry';

	private const KIND_TEXT = 'text';
	private const KIND_BINARY = 'binary';

	private Validator $validator;

	/** @var \Closure(int): array */
	private \Closure $readForm;

	/** @var \Closure(): bool */
	private \Closure $canReadForms;

	private ?bool $isFormReader = null;

	/**
	 * @param callable(int): array|null $readForm Reader of a CRM form by id: NAME; empty when there is none.
	 * @param callable(): bool|null $canReadForms Whether the current user may read CRM forms.
	 */
	public function __construct(
		?Validator $validator = null,
		?callable $readForm = null,
		?callable $canReadForms = null
	)
	{
		$this->validator = $validator ?? new Validator();
		$this->readForm = $readForm
			? \Closure::fromCallable($readForm)
			: static fn(int $formId): array => Subtype\Form::getFormById($formId)
		;
		$this->canReadForms = $canReadForms
			? \Closure::fromCallable($canReadForms)
			: static fn(): bool => Loader::includeModule('crm') && WebForm\Manager::checkReadPermission()
		;
	}

	/**
	 * @return array{entry: array, files: list<array>, images: list<array>, forms: list<array>, meta: array}|null
	 *  Null when the entry of the version cannot be read.
	 */
	public function export(Manifest $manifest): ?array
	{
		$reverseMap = $this->buildReverseMap($manifest);

		$entryContent = FileStore::readEntry($manifest);
		if ($entryContent === null)
		{
			return null;
		}

		$files = [];
		foreach ($this->collectAssetFiles($manifest) as $file)
		{
			if ($file['kind'] === self::KIND_TEXT)
			{
				$content = FileStore::readContent($file['fileId']);
				if ($content !== null)
				{
					$file['content'] = $this->substituteBack($content, $reverseMap);
					$file['size'] = strlen($content);
					$files[] = $file;
					continue;
				}
			}
			$file['size'] = $this->resolveStoredFileSize($file['fileId']);
			$files[] = $file;
		}

		$entry = $manifest->getEntry() ?? [];

		return [
			'entry' => [
				'path' => (string)($entry['path'] ?? ''),
				'content' => $this->substituteBack($entryContent, $reverseMap),
				'hash' => (string)($entry['hash'] ?? ''),
				'size' => strlen($entryContent),
			],
			'files' => $files,
			'images' => $this->collectImages($manifest),
			'forms' => $this->collectForms($manifest),
			'meta' => $manifest->getMeta(),
		];
	}

	/**
	 * The version without contents: the entry first, then every package file. Sizes are
	 * of the contract form, the bytes readPart() returns.
	 * @return array{
	 *     files: list<array{path: string, kind: string, size: int, hash: string, fileId: int}>,
	 *     images: list<array>,
	 *     forms: list<array{token: string, design: array, formId: int, formName: string}>,
	 *     meta: array,
	 * }|null Null when the entry of the version cannot be read.
	 */
	public function inventory(Manifest $manifest): ?array
	{
		$files = [];
		foreach ($this->collectFiles($manifest) as $file)
		{
			$size = $this->resolveContractSize($manifest, $file);
			if ($size === null && $file['kind'] === self::KIND_ENTRY)
			{
				return null;
			}

			$files[] = [
				'path' => $file['path'],
				'kind' => $file['kind'],
				'size' => (int)$size,
				'hash' => $file['hash'],
				'fileId' => $file['fileId'],
			];
		}

		return [
			'files' => $files,
			'images' => $this->collectImages($manifest),
			'forms' => $this->collectForms($manifest),
			'meta' => $manifest->getMeta(),
		];
	}

	/**
	 * One part of a package file in the contract form.
	 * @param int $offset Byte offset of the part in the file.
	 * @param int $chunkBytes Max length of the part in base64 characters.
	 * @return array{path: string, content: string, offset: int, nextOffset: int|null}|null
	 *  Content is base64; nextOffset is null on the last part. Null when the stored file cannot be read.
	 * @throws \InvalidArgumentException When the version has no file at the path or the offset is past its end.
	 */
	public function readPart(Manifest $manifest, string $path, int $offset, int $chunkBytes): ?array
	{
		$file = $this->findFile($manifest, $path);
		if ($file === null)
		{
			throw new \InvalidArgumentException(
				"The markup package has no file at path {$path}. Take the paths from the package inventory."
			);
		}

		// whole base64 quanta: every part decodes on its own
		$slice = $this->readContractSlice($manifest, $file, $offset, max(3, intdiv($chunkBytes, 4) * 3));
		if ($slice === null)
		{
			return null;
		}

		$size = $slice['size'];
		if ($offset < 0 || $offset > $size)
		{
			throw new \InvalidArgumentException(
				"The offset {$offset} is out of the file {$path} of {$size} bytes. "
				. 'Pass 0 or nextOffset of the previous part.'
			);
		}

		$part = $slice['part'];
		$nextOffset = $offset + strlen($part);

		return [
			'path' => $file['path'],
			'content' => base64_encode($part),
			'offset' => $offset,
			'nextOffset' => $nextOffset < $size ? $nextOffset : null,
		];
	}

	/**
	 * @return list<array{path: string, kind: string, fileId: int, hash: string}> The entry first.
	 */
	private function collectFiles(Manifest $manifest): array
	{
		$entry = $manifest->getEntry() ?? [];

		return array_merge(
			[[
				'path' => (string)($entry['path'] ?? ''),
				'kind' => self::KIND_ENTRY,
				'fileId' => (int)($entry['fileId'] ?? 0),
				'hash' => (string)($entry['hash'] ?? ''),
			]],
			$this->collectAssetFiles($manifest),
		);
	}

	/**
	 * Package files of the version; downloaded external images are declared by token, not listed.
	 * @return list<array{path: string, kind: string, fileId: int, hash: string}>
	 */
	private function collectAssetFiles(Manifest $manifest): array
	{
		$files = [];
		foreach ($manifest->getAssets() as $asset)
		{
			if (isset($asset['token']))
			{
				continue;
			}

			$path = (string)$asset['path'];
			$files[] = [
				'path' => $path,
				'kind' => $this->validator->resolveTypeByPath($path) ?? self::KIND_BINARY,
				'fileId' => (int)$asset['fileId'],
				'hash' => (string)($asset['hash'] ?? ''),
			];
		}

		return $files;
	}

	/**
	 * Image declarations of the version with their outcome. A manifest without declarations
	 * (older versions) lists its downloaded external images by token.
	 * @return list<array>
	 */
	private function collectImages(Manifest $manifest): array
	{
		$images = $manifest->getImages();
		if ($images)
		{
			return $images;
		}

		foreach ($manifest->getAssets() as $asset)
		{
			if (isset($asset['token']))
			{
				$images[] = [
					'token' => (string)$asset['token'],
					'kind' => Package::IMAGE_KIND_EXTERNAL,
					'url' => (string)($asset['sourceUrl'] ?? ''),
				];
			}
		}

		return $images;
	}

	/**
	 * @return list<array{token: string, design: array, formId: int, formName: string}>
	 */
	private function collectForms(Manifest $manifest): array
	{
		$forms = [];
		foreach ($manifest->getForms() as $token => $binding)
		{
			$form = $this->isFormReader() ? ($this->readForm)($binding['formId']) : [];
			$forms[] = [
				'token' => (string)$token,
				'design' => $this->toDeclaredDesign($binding['design']),
				'formId' => $binding['formId'],
				'formName' => (string)($form['NAME'] ?? ''),
			];
		}

		return $forms;
	}

	private function isFormReader(): bool
	{
		$this->isFormReader ??= ($this->canReadForms)();

		return $this->isFormReader;
	}

	/**
	 * The stored design of a binding is that of the CRM form (color, dark); the declaration
	 * of the package names the same values colors and theme.
	 * @return array{colors?: array<string, string>, font?: array, style?: string, theme?: string}
	 */
	private function toDeclaredDesign(array $design): array
	{
		$declared = [];
		$colors = array_intersect_key((array)($design['color'] ?? []), array_flip(CrmFormColorNormalizer::COLOR_KEYS));
		if ($colors)
		{
			$declared['colors'] = $colors;
		}
		if (isset($design['font']))
		{
			$declared['font'] = $design['font'];
		}
		if (isset($design['style']))
		{
			$declared['style'] = $design['style'];
		}
		if (isset($design['dark']))
		{
			$declared['theme'] = $design['dark'] ? 'dark' : 'light';
		}

		return $declared;
	}

	private function findFile(Manifest $manifest, string $path): ?array
	{
		if ($path === '')
		{
			return null;
		}

		foreach ($this->collectFiles($manifest) as $file)
		{
			if ($file['path'] === $path)
			{
				return $file;
			}
		}

		return null;
	}

	/**
	 * A part of a file in the contract form and the size of the whole file.
	 * A current version file is stored in the contract form: only the part range is read.
	 * A text of a first version manifest is substituted back as a whole first.
	 * @return array{part: string, size: int}|null Empty part when the offset is out of the file.
	 */
	private function readContractSlice(Manifest $manifest, array $file, int $offset, int $length): ?array
	{
		if ($this->needsSubstitutionBack($manifest, $file))
		{
			$content = $this->readContractContent($manifest, $file);
			if ($content === null)
			{
				return null;
			}

			$size = strlen($content);

			return [
				'part' => $offset >= 0 && $offset < $size ? substr($content, $offset, $length) : '',
				'size' => $size,
			];
		}

		return $this->readStoredRange($file['fileId'], $offset, $length);
	}

	/**
	 * A local file is read by range; a file in a cloud storage is downloaded by the core
	 * to a temporary copy first (CFile::makeFileArray), the range is then read from the copy.
	 * @return array{part: string, size: int}|null
	 */
	private function readStoredRange(int $fileId, int $offset, int $length): ?array
	{
		$fileArray = FileStore::isPackageFile($fileId) ? \CFile::makeFileArray($fileId) : null;
		$filePath = is_array($fileArray) ? (string)($fileArray['tmp_name'] ?? '') : '';
		if ($filePath === '')
		{
			return null;
		}

		$size = @filesize($filePath);
		if ($size === false)
		{
			return null;
		}

		if ($offset < 0 || $offset >= $size)
		{
			return ['part' => '', 'size' => $size];
		}

		$handle = @fopen($filePath, 'rb');
		if ($handle === false)
		{
			return null;
		}

		$part = fseek($handle, $offset) === 0 ? stream_get_contents($handle, $length) : false;
		fclose($handle);

		return $part === false ? null : ['part' => $part, 'size' => $size];
	}

	protected function readWholeContent(int $fileId): ?string
	{
		return FileStore::readContent($fileId);
	}

	private function readContractContent(Manifest $manifest, array $file): ?string
	{
		$content = $this->readWholeContent($file['fileId']);
		if ($content === null || !$this->needsSubstitutionBack($manifest, $file))
		{
			return $content;
		}

		return $this->substituteBack($content, $this->buildReverseMap($manifest));
	}

	/**
	 * Current version files are stored in the contract form, their stored size is the answer;
	 * texts of a first version manifest are measured after the substitution back.
	 */
	private function resolveContractSize(Manifest $manifest, array $file): ?int
	{
		if ($this->needsSubstitutionBack($manifest, $file))
		{
			$content = $this->readContractContent($manifest, $file);

			return $content === null ? null : strlen($content);
		}

		$stored = $file['fileId'] > 0 ? \CFile::getFileArray($file['fileId']) : false;

		return is_array($stored) ? (int)($stored['FILE_SIZE'] ?? 0) : null;
	}

	private function needsSubstitutionBack(Manifest $manifest, array $file): bool
	{
		return $manifest->getVersion() < 2
			&& in_array($file['kind'], [self::KIND_ENTRY, self::KIND_TEXT], true);
	}

	/**
	 * Portal urls of a first version manifest mapped back to package paths and bx-asset:// tokens;
	 * empty for the current version, whose texts hold no portal urls.
	 * @return array<string, string>
	 */
	private function buildReverseMap(Manifest $manifest): array
	{
		if ($manifest->getVersion() >= 2)
		{
			return [];
		}

		$reverseMap = [];
		foreach ($manifest->getAssets() as $asset)
		{
			$url = (string)\CFile::getPath((int)$asset['fileId']);
			if ($url === '')
			{
				continue;
			}

			// a downloaded external image reverses to its token, not to a file
			$reverseMap[$url] = isset($asset['token'])
				? Intake::TOKEN_SCHEME . (string)$asset['token']
				: (string)$asset['path'];
		}

		return $reverseMap;
	}

	/**
	 * The reverse of the intake substitution. Urls are unique portal addresses, so a
	 * plain textual replacement restores every quoted form the intake produced.
	 */
	private function substituteBack(string $text, array $reverseMap): string
	{
		$urls = array_keys($reverseMap);
		usort($urls, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
		foreach ($urls as $url)
		{
			$text = str_replace($url, $reverseMap[$url], $text);
		}

		return $text;
	}

	private function resolveStoredFileSize(int $fileId): int
	{
		$file = \CFile::getFileArray($fileId);

		return is_array($file) ? (int)($file['FILE_SIZE'] ?? 0) : 0;
	}
}
