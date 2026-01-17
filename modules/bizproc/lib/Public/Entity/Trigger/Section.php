<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Trigger;

use Bitrix\Main\Type\Contract\Arrayable;

final class Section implements Arrayable
{
	protected const ID_ARRAY_KEY = 'id';
	protected const PATH_ARRAY_KEY = 'path';

	public function __construct(
		public readonly string $id,
		public readonly ?string $path = null,
	)
	{}

	public function toArray(): array
	{
		return [
			Section::ID_ARRAY_KEY => $this->id,
			Section::PATH_ARRAY_KEY => $this->path,
		];
	}
}