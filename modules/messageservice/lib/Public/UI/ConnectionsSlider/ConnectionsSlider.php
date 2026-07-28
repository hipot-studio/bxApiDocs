<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ConnectionsSlider;

final readonly class ConnectionsSlider implements \JsonSerializable
{
	public function __construct(
		/** @var Page[] */
		private array $pages
	)
	{
	}

	/**
	 * @return Page[]
	 */
	public function getPages(): array
	{
		return $this->pages;
	}

	public function jsonSerialize(): array
	{
		return [
			'pages' => $this->getPages(),
		];
	}
}
