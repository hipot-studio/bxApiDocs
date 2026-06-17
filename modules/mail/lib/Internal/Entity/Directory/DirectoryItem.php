<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Directory;

final readonly class DirectoryItem
{
	/**
	 * @param DirectoryItem[] $children
	 */
	public function __construct(
		public int $id,
		public string $dirMd5,
		public string $name,
		public string $formattedName,
		public string $path,
		public int $level,
		public bool $isSync,
		public bool $isDisabled,
		public bool $isContainer,
		public bool $hasChild,
		public string $type,
		public array $children,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'dirMd5' => $this->dirMd5,
			'name' => $this->name,
			'formattedName' => $this->formattedName,
			'path' => $this->path,
			'level' => $this->level,
			'isSync' => $this->isSync,
			'isDisabled' => $this->isDisabled,
			'isContainer' => $this->isContainer,
			'hasChild' => $this->hasChild,
			'type' => $this->type,
			'children' => array_map(static fn (self $child) => $child->toArray(), $this->children),
		];
	}
}
