<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

final class Verdict
{
	private function __construct(private readonly ?RestAccessDenialExceptionInterface $denial)
	{
	}

	public static function grant(): self
	{
		return new self(null);
	}

	public static function deny(RestAccessDenialExceptionInterface $denial): self
	{
		return new self($denial);
	}

	public function isGrant(): bool
	{
		return $this->denial === null;
	}

	public function getDenial(): ?RestAccessDenialExceptionInterface
	{
		return $this->denial;
	}
}
