<?php
namespace Bitrix\Sign\Item\Fs;

use \Bitrix\Sign\Contract;

class File implements Contract\Item
{
	public FileContent $content;
	public string $name;
	public string $dir = '';
	public ?string $type = null;
	public ?int $id = null;
	public ?int $size = 0;
	public ?bool $isImage;

	/**
	 * @param string $name
	 * @param string $dir
	 * @param string|null $type
	 * @param int|null $id
	 * @param FileContent|null $content
	 */
	public function __construct(
		string $name,
		string $dir = '',
		?string $type = null,
		?int $id = null,
		?FileContent $content = null,
		?bool $isImage = null
	) {
		$this->id = $id;
		$this->type = $type;
		$this->dir = $dir;
		$this->name = $name;
		$this->content = $content ?: new FileContent();
		// Byte length, not character length: the size feeds upload limits stated in bytes, and on
		// binary content mb_strlen reports about a fifth less than the file really weighs.
		$this->size = $this->content->data ? strlen($this->content->data) : 0;
		$this->isImage = $isImage;
	}

	public static function createByLegacyFile(\Bitrix\Sign\File $file): static
	{
		return new static(
			$file->getName(),
			\Bitrix\Main\IO\Path::getDirectory($file->getPath()),
			$file->getType(),
			$file->getId(),
			new FileContent($file->getContent()),
			$file->isImage()
		);
	}

	public function getPath(): string
	{
		return $this->dir . DIRECTORY_SEPARATOR . $this->name;
	}
}