<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

class Sources extends AbstractField
{
	protected array $sources = [];

	public function addSource(
		int $id,
		string $url,
		?string $title = null,
		?string $description = null
	): self
	{
		$metaData = isset($title) || isset($description)
			? ['title' => $title, 'description' => $description]
			: null
		;

		$this->sources[$id] = [
			'url' => $url,
			'metaData' => $metaData,
		];

		return $this;
	}

	public function jsonSerialize(): array
	{
		return $this->sources;
	}

	public static function create(): self
	{
		return new self();
	}
}
