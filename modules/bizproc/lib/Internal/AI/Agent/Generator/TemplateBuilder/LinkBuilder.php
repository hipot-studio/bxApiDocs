<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class LinkBuilder
{
	private array $links = [];

	public function connect(NodeOutput $from, NodeInput $to): void
	{
		$this->links[] = [
			"{$from->name}:{$from->port}",
			"{$to->name}:{$to->port}",
		];
	}

	public function getLinks(): array
	{
		return $this->links;
	}
}
