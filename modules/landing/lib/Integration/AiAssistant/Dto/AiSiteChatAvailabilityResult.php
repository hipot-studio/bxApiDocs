<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

final class AiSiteChatAvailabilityResult
{
	private function __construct(
		private readonly bool $available,
		private readonly ?string $reasonCode = null,
	)
	{
	}

	public static function available(): self
	{
		return new self(true);
	}

	public static function unavailable(string $reasonCode): self
	{
		return new self(false, $reasonCode);
	}

	public function isAvailable(): bool
	{
		return $this->available;
	}

	public function getReasonCode(): ?string
	{
		return $this->reasonCode;
	}

	public function getReasonCodeOrDefault(string $default = ''): string
	{
		return $this->reasonCode ?? $default;
	}
}
