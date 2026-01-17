<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Payload;

final class Document
{
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description = '',
		public readonly array $extraData = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'extra_data' => $this->extraData,
		];
	}
}