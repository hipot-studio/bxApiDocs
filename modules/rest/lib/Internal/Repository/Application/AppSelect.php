<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

final class AppSelect
{
	private bool $attributes = false;
	private bool $langs = false;

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
}
