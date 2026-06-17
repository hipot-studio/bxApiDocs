<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Directory;

final readonly class AssignedDirectory
{
	public function __construct(
		public string $dirMd5,
		public string $formattedName,
		public string $path,
		public string $type,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'dirMd5' => $this->dirMd5,
			'formattedName' => $this->formattedName,
			'path' => $this->path,
			'type' => $this->type,
		];
	}
}
