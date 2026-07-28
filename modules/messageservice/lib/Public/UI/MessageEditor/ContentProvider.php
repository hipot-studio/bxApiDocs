<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

abstract class ContentProvider implements \JsonSerializable
{
	abstract public function getId(): string;

	/**
	 * Arbitrary data for frontend. Override to pass provider-specific configuration.
	 */
	protected function getCustomData(): array
	{
		return [];
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'customData' => $this->getCustomData(),
		];
	}
}
