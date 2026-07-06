<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

use Bitrix\Main\Provider\Params\SelectInterface;

final class ApplicationSelect implements SelectInterface
{
	public const FIELD_ATTRIBUTES = 'attributes';
	public const FIELD_LANGS = 'langs';

	private bool $attributes = false;
	private bool $langs = false;

	public function __construct(array $select = [])
	{
		foreach ($select as $field)
		{
			match ($field) {
				self::FIELD_ATTRIBUTES => $this->attributes(),
				self::FIELD_LANGS      => $this->langs(),
				default                => null,
			};
		}
	}

	public function attributes(bool $enabled = true): self
	{
		$this->attributes = $enabled;

		return $this;
	}

	public function langs(bool $enabled = true): self
	{
		$this->langs = $enabled;

		return $this;
	}

	public function hasAttributes(): bool
	{
		return $this->attributes;
	}

	public function hasLangs(): bool
	{
		return $this->langs;
	}

	public function prepareSelect(): array
	{
		$out = [];

		if ($this->attributes)
		{
			$out[] = self::FIELD_ATTRIBUTES;
		}

		if ($this->langs)
		{
			$out[] = self::FIELD_LANGS;
		}

		return $out;
	}
}
