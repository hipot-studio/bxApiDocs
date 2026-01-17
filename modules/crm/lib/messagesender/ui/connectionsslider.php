<?php

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Page;

final class ConnectionsSlider implements \JsonSerializable
{
	public function __construct(
		/** @var Page[] */
		private readonly array $pages
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
