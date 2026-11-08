<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\PageLayout;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Section implements JsonSerializable, Arrayable
{
	public function __construct(
		public string $id,
		public string $title,
		public array $elements,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'elements' => array_map(static fn (Element $element) => $element->toArray(), $this->elements),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
