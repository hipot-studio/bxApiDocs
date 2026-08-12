<?php

declare(strict_types=1);

namespace Bitrix\ImOpenLines\V2\Analytics\Bot;

final class EndBotSessionEventContext
{
	private ?string $botCode = null;
	private ?string $mode = null;

	public const MODE_MANUAL = 'manual';
	public const MODE_AUTO = 'auto';

	public function setBotCode(?string $botCode): self
	{
		$this->botCode = $botCode;

		return $this;
	}

	public function getBotCode(): ?string
	{
		return $this->botCode;
	}

	public function setMode(string $mode): self
	{
		$this->mode = $mode;

		return $this;
	}

	public function getMode(): ?string
	{
		return $this->mode;
	}

	public function isValid(): bool
	{
		return !empty($this->botCode) && !empty($this->mode);
	}
}
