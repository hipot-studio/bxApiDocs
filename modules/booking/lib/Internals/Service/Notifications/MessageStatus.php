<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

class MessageStatus
{
	public const SEMANTIC_SUCCESS = 'success';
	public const SEMANTIC_FAILURE = 'failure';

	private function __construct(
		private readonly string $name,
		private readonly string $semantic,
	)
	{
	}

	public static function success(string $name): self
	{
		return new self($name, self::SEMANTIC_SUCCESS);
	}

	public static function failure(string $name): self
	{
		return new self($name, self::SEMANTIC_FAILURE);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getSemantic(): string
	{
		return $this->semantic;
	}
}
