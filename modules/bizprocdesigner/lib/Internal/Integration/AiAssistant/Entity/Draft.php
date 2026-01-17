<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;
use Bitrix\Main\Type\Contract\Arrayable;

class Draft implements Arrayable
{
	public function __construct(
		public readonly int $draftId = 0,
		public readonly int $templateId = 0,
		public readonly int $userId = 0,
		public readonly BlockCollection $blocks = new BlockCollection(),
		public readonly ConnectionCollection $connections = new ConnectionCollection(),
	)
	{
	}

	public static function createFromArray(array $data): self
	{
		$blocks = new BlockCollection();
		$connections = new ConnectionCollection();

		if (!empty($data['blocks']))
		{
			$blocks->fill($data['blocks']);
		}

		if (!empty($data['connections']))
		{
			$connections->fill($data['connections']);
		}

		return new static(
			(int)($data['draftId'] ?? 0),
			(int)($data['templateId'] ?? 0),
			(int)($data['userId'] ?? 0),
			$blocks,
			$connections,
		);
	}

	public function toArray(): array
	{
		return [
			'draftId' => $this->draftId,
			'templateId' => $this->templateId,
			'userId' => $this->userId,
			'blocks' => $this->blocks->toArray(),
			'connections' => $this->connections->toArray(),
		];
	}
}
