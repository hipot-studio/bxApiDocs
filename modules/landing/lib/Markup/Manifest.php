<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Main\Web\Json;

/**
 * Manifest of a stored markup package version.
 *
 * Describes the files of one custom code block version: the entry document and the assets.
 * Holds identifiers, hashes and content types only, never file contents. Serialized as JSON
 * into the MARKUP_MANIFEST column of the block row.
 *
 * Version 2 stores the content type of every file; a version 1 manifest has none, its types
 * are derived from the path extensions on read. Version 3 adds the image declarations of the
 * package with their outcome - the file of a successful image stays an asset with the token,
 * a failed one has no file and is shown as a placeholder - and the CRM form bindings of the
 * markers: token -> {formId, design}; an older manifest has none and is serialized as it was.
 */
class Manifest
{
	public const VERSION = 3;

	public const OUTCOME_GENERATED = 'generated';
	public const OUTCOME_DOWNLOADED = 'downloaded';
	public const OUTCOME_FAILED = 'failed';

	private const OUTCOMES = [self::OUTCOME_GENERATED, self::OUTCOME_DOWNLOADED, self::OUTCOME_FAILED];
	private const IMAGE_FIELDS = ['token', 'kind', 'prompt', 'aspect', 'url', 'alt'];

	public const ENTRY_TYPE = 'text/html';
	public const TYPE_UNKNOWN = 'application/octet-stream';

	private const TYPE_BY_EXTENSION = [
		'html' => 'text/html',
		'css' => 'text/css',
		'js' => 'text/javascript',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
		'gif' => 'image/gif',
		'mp4' => 'video/mp4',
	];

	private const IMAGE_TYPES = [
		IMAGETYPE_PNG => 'image/png',
		IMAGETYPE_JPEG => 'image/jpeg',
		IMAGETYPE_WEBP => 'image/webp',
		IMAGETYPE_GIF => 'image/gif',
	];

	private int $version = self::VERSION;

	/** @var array{path: string, fileId: int, hash: string, type: string}|null */
	private ?array $entry = null;

	/**
	 * @var array<int, array{path: string, fileId: int, hash: string, type: string, sourceUrl?: string, token?: string}>
	 */
	private array $assets = [];

	/**
	 * @var array<int, array{token: string, kind: string, outcome: string, prompt?: string, aspect?: string,
	 *     url?: string, alt?: string, reason?: string}>
	 */
	private array $images = [];

	private array $meta = [];

	/** @var array<string, array{formId: int, design: array}> */
	private array $forms = [];

	public static function fromJson(?string $json): ?self
	{
		if ($json === null || $json === '')
		{
			return null;
		}

		try
		{
			$data = Json::decode($json);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return null;
		}

		if (!is_array($data) || !isset($data['entry']['fileId']))
		{
			return null;
		}

		$manifest = new self();
		$manifest->version = max(1, (int)($data['version'] ?? 1));
		$manifest->setEntry(
			(string)($data['entry']['path'] ?? ''),
			(int)$data['entry']['fileId'],
			(string)($data['entry']['hash'] ?? ''),
			(string)($data['entry']['type'] ?? '')
		);
		foreach ((array)($data['assets'] ?? []) as $asset)
		{
			if (is_array($asset) && isset($asset['fileId']))
			{
				$manifest->addAsset($asset);
			}
		}
		$manifest->setImages((array)($data['images'] ?? []));
		$manifest->setMeta((array)($data['meta'] ?? []));
		$manifest->setForms(is_array($data['forms'] ?? null) ? $data['forms'] : []);

		return $manifest;
	}

	public function toJson(): string
	{
		$data = [
			'version' => $this->version,
			'entry' => $this->entry,
			'assets' => $this->assets,
		];
		if ($this->images)
		{
			$data['images'] = $this->images;
		}
		$data['meta'] = $this->meta;
		if ($this->forms)
		{
			$data['forms'] = $this->forms;
		}

		return Json::encode($data);
	}

	/**
	 * Version of the manifest format the data was written in.
	 */
	public function getVersion(): int
	{
		return $this->version;
	}

	/**
	 * @param string $type Content type; empty - derived from the path.
	 */
	public function setEntry(string $path, int $fileId, string $hash, string $type = ''): void
	{
		$this->entry = [
			'path' => $path,
			'fileId' => $fileId,
			'hash' => $hash,
			'type' => $type !== '' ? $type : self::ENTRY_TYPE,
		];
	}

	/**
	 * @param array{path: string, fileId: int, hash: string, type?: string, sourceUrl?: string, token?: string} $asset
	 * An asset without a type gets the one of its path extension.
	 */
	public function addAsset(array $asset): void
	{
		$path = (string)($asset['path'] ?? '');
		$type = (string)($asset['type'] ?? '');
		$this->assets[] = [
			'path' => $path,
			'fileId' => (int)$asset['fileId'],
			'hash' => (string)($asset['hash'] ?? ''),
			'type' => $type !== '' ? $type : self::typeByPath($path),
		]
		+ (isset($asset['sourceUrl']) ? ['sourceUrl' => (string)$asset['sourceUrl']] : [])
		+ (isset($asset['token']) ? ['token' => (string)$asset['token']] : []);
	}

	/**
	 * Content type of a package file by its bytes: an image by its signature (a png named
	 * .jpg is a png); text and video files, whose content the validator has already matched
	 * with the extension, by the extension.
	 */
	public static function typeByContent(string $path, string $content): string
	{
		$type = self::typeByPath($path);
		if (!str_starts_with($type, 'image/'))
		{
			return $type;
		}

		$info = @getimagesizefromstring($content);

		return is_array($info) && isset(self::IMAGE_TYPES[$info[2]]) ? self::IMAGE_TYPES[$info[2]] : $type;
	}

	/**
	 * Content type of a package file by its path extension, for manifests without stored types.
	 */
	public static function typeByPath(string $path): string
	{
		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

		return self::TYPE_BY_EXTENSION[$extension] ?? self::TYPE_UNKNOWN;
	}

	public function getEntry(): ?array
	{
		return $this->entry;
	}

	public function getEntryFileId(): int
	{
		return (int)($this->entry['fileId'] ?? 0);
	}

	/**
	 * @return array<int, array>
	 */
	public function getAssets(): array
	{
		return $this->assets;
	}

	/**
	 * Image declarations of the version with their outcome. A declaration without a token is
	 * dropped, one without a known outcome is a failed one; the regenerate flag of a submitted
	 * declaration is a one-off order, never stored. A failed declaration keeps the reason of its
	 * failure, so a version that inherits the failure names the same reason; a declaration written
	 * before the reason existed has none.
	 * @param array<int, array> $images
	 */
	public function setImages(array $images): void
	{
		$this->images = [];
		foreach ($images as $image)
		{
			if (!is_array($image) || (string)($image['token'] ?? '') === '')
			{
				continue;
			}

			$declaration = [];
			foreach (self::IMAGE_FIELDS as $field)
			{
				if (isset($image[$field]))
				{
					$declaration[$field] = (string)$image[$field];
				}
			}
			$outcome = (string)($image['outcome'] ?? '');
			$declaration['outcome'] = in_array($outcome, self::OUTCOMES, true) ? $outcome : self::OUTCOME_FAILED;
			$reason = (string)($image['reason'] ?? '');
			if ($declaration['outcome'] === self::OUTCOME_FAILED && $reason !== '')
			{
				$declaration['reason'] = $reason;
			}

			$this->images[] = $declaration;
		}
	}

	/**
	 * @return array<int, array{token: string, kind: string, outcome: string, prompt?: string, aspect?: string,
	 *     url?: string, alt?: string, reason?: string}>
	 */
	public function getImages(): array
	{
		return $this->images;
	}

	public function findImage(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}
		foreach ($this->images as $image)
		{
			if ($image['token'] === $token)
			{
				return $image;
			}
		}

		return null;
	}

	/**
	 * The stored file of an image token; null when the image has no file (failed or not declared).
	 */
	public function findAssetByToken(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}
		foreach ($this->assets as $asset)
		{
			if (($asset['token'] ?? '') === $token)
			{
				return $asset;
			}
		}

		return null;
	}

	public function setMeta(array $meta): void
	{
		$this->meta = $meta;
	}

	public function getMeta(): array
	{
		return $this->meta;
	}

	/**
	 * Replaces the form bindings of the version. A binding without a valid token or a form id is dropped.
	 * @param array<string, array{formId: int, design?: array}> $forms
	 */
	public function setForms(array $forms): void
	{
		$this->forms = [];
		foreach ($forms as $token => $binding)
		{
			$formId = is_array($binding) ? (int)($binding['formId'] ?? 0) : 0;
			if ($formId <= 0 || !preg_match(Validator::TOKEN_REGEX, (string)$token))
			{
				continue;
			}
			$this->forms[(string)$token] = [
				'formId' => $formId,
				'design' => is_array($binding['design'] ?? null) ? $binding['design'] : [],
			];
		}
	}

	/**
	 * @return array<string, array{formId: int, design: array}>
	 */
	public function getForms(): array
	{
		return $this->forms;
	}

	/**
	 * @return array{formId: int, design: array}|null
	 */
	public function getFormBinding(string $token): ?array
	{
		return $this->forms[$token] ?? null;
	}

	/**
	 * All file ids of the version: the entry and every asset.
	 * @return int[]
	 */
	public function getFileIds(): array
	{
		$ids = [];
		if ($this->entry)
		{
			$ids[] = (int)$this->entry['fileId'];
		}
		foreach ($this->assets as $asset)
		{
			$ids[] = (int)$asset['fileId'];
		}

		return array_values(array_unique(array_filter($ids)));
	}

	public function containsFileId(int $fileId): bool
	{
		return in_array($fileId, $this->getFileIds(), true);
	}

	public function findByHash(string $hash): ?array
	{
		if ($hash === '')
		{
			return null;
		}
		if ($this->entry && $this->entry['hash'] === $hash)
		{
			return $this->entry;
		}
		foreach ($this->assets as $asset)
		{
			if ($asset['hash'] === $hash)
			{
				return $asset;
			}
		}

		return null;
	}

	public function findBySourceUrl(string $url): ?array
	{
		if ($url === '')
		{
			return null;
		}
		foreach ($this->assets as $asset)
		{
			if (($asset['sourceUrl'] ?? '') === $url)
			{
				return $asset;
			}
		}

		return null;
	}
}
