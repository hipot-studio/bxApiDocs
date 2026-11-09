<?php

namespace Bitrix\Landing\Markup;

/**
 * Incoming markup package: the unit of intake from any source.
 *
 * Value object: the entry document, asset files, the image and form declarations and the page meta.
 * Contents live in memory (package size is limited by Validator), nothing is persisted here.
 */
class Package
{
	public const IMAGE_KIND_GENERATE = 'generate';
	public const IMAGE_KIND_EXTERNAL = 'external';

	/** @var array{path: string, content: string}|null */
	private ?array $entry = null;

	/** @var array<int, array{path: string, content: string}> */
	private array $assets = [];

	/** @var array<int, array{token: string, kind: string, prompt?: string, aspect?: string, url?: string, alt?: string}> */
	private array $imageDeclaration = [];

	/**
	 * Elements of the form declaration as the source gave them; a bound one also carries
	 * the portal field `binding`.
	 * @var array<int, array>
	 */
	private array $formDeclaration = [];

	private array $meta = [];

	public function setEntry(string $path, string $content): void
	{
		$this->entry = [
			'path' => $path,
			'content' => $content,
		];
	}

	public function getEntry(): ?array
	{
		return $this->entry;
	}

	public function addAsset(string $path, string $content): void
	{
		$this->assets[] = [
			'path' => $path,
			'content' => $content,
		];
	}

	/**
	 * @return array<int, array{path: string, content: string}>
	 */
	public function getAssets(): array
	{
		return $this->assets;
	}

	public function removeAsset(string $path): void
	{
		$this->assets = array_values(array_filter(
			$this->assets,
			static fn(array $asset): bool => $asset['path'] !== $path,
		));
	}

	/**
	 * @param array{token: string, kind: string, prompt?: string, aspect?: string, url?: string, alt?: string} $image
	 */
	public function addImage(array $image): void
	{
		$this->imageDeclaration[] = $image;
	}

	/**
	 * @return array<int, array>
	 */
	public function getImageDeclaration(): array
	{
		return $this->imageDeclaration;
	}

	/**
	 * Adds an element of the form declaration from the source of the package. Service fields
	 * of the portal never come from outside, so they are cut off here.
	 */
	public function addForm(array $form): void
	{
		unset($form['binding'], $form['formId'], $form['formName']);
		$this->formDeclaration[] = $form;
	}

	/**
	 * @return array<int, array>
	 */
	public function getFormDeclaration(): array
	{
		return $this->formDeclaration;
	}

	/**
	 * Binds the token to a CRM form of the portal. A token placed by a marker only, with no
	 * declaration, gets an element of its own to carry the binding.
	 */
	public function bindForm(string $token, int $formId, array $design): void
	{
		$binding = [
			'formId' => $formId,
			'design' => $design,
		];

		$isDeclared = false;
		foreach ($this->formDeclaration as $index => $form)
		{
			if (($form['token'] ?? null) === $token)
			{
				$this->formDeclaration[$index]['binding'] = $binding;
				$isDeclared = true;
			}
		}

		if (!$isDeclared)
		{
			$this->formDeclaration[] = [
				'token' => $token,
				'binding' => $binding,
			];
		}
	}

	/**
	 * Takes the binding off the token. An element made by bindForm() only to carry the binding goes away.
	 */
	public function unbindForm(string $token): void
	{
		foreach ($this->formDeclaration as $index => $form)
		{
			if (($form['token'] ?? null) !== $token)
			{
				continue;
			}

			unset($form['binding']);
			if (array_keys($form) === ['token'])
			{
				unset($this->formDeclaration[$index]);
			}
			else
			{
				$this->formDeclaration[$index] = $form;
			}
		}
		$this->formDeclaration = array_values($this->formDeclaration);
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
	 * All files of the package: the entry first, then the assets.
	 * @return array<int, array{path: string, content: string}>
	 */
	public function getFiles(): array
	{
		$files = [];
		if ($this->entry)
		{
			$files[] = $this->entry;
		}

		return array_merge($files, $this->assets);
	}

	public function getFilesCount(): int
	{
		return count($this->getFiles());
	}

	public function getTotalSize(): int
	{
		$size = 0;
		foreach ($this->getFiles() as $file)
		{
			$size += strlen($file['content']);
		}

		return $size;
	}

	/**
	 * Serializes the package into a generation-state-safe array: binary assets go base64.
	 */
	public function toStateArray(): array
	{
		$assets = [];
		foreach ($this->assets as $asset)
		{
			$binary = !self::isTextPath($asset['path']);
			$assets[] = [
				'path' => $asset['path'],
				'content' => $binary ? base64_encode($asset['content']) : $asset['content'],
				'binary' => $binary,
			];
		}

		return [
			'entry' => $this->entry,
			'assets' => $assets,
			'images' => $this->imageDeclaration,
			'forms' => $this->formDeclaration,
			'meta' => $this->meta,
		];
	}

	public static function fromStateArray(array $state): self
	{
		$package = new self();
		if (isset($state['entry']['path'], $state['entry']['content']))
		{
			$package->setEntry((string)$state['entry']['path'], (string)$state['entry']['content']);
		}
		foreach ((array)($state['assets'] ?? []) as $asset)
		{
			if (!isset($asset['path'], $asset['content']))
			{
				continue;
			}
			$content = (string)$asset['content'];
			if (!empty($asset['binary']))
			{
				$content = (string)base64_decode($content, true);
			}
			$package->addAsset((string)$asset['path'], $content);
		}
		foreach ((array)($state['images'] ?? []) as $image)
		{
			if (is_array($image))
			{
				$package->addImage($image);
			}
		}
		foreach ((array)($state['forms'] ?? []) as $form)
		{
			// the state is written by the portal: the binding is kept, unlike in addForm()
			if (is_array($form))
			{
				$package->formDeclaration[] = $form;
			}
		}
		$package->setMeta((array)($state['meta'] ?? []));

		return $package;
	}

	private static function isTextPath(string $path): bool
	{
		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

		return in_array($extension, ['css', 'js'], true);
	}
}
