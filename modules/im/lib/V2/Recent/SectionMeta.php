<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat\Collab\CollabInfo;
use Bitrix\Im\V2\Rest\RestConvertible;

class SectionMeta implements RestConvertible
{
	public function __construct(
		private readonly array $fixedChatIds = [],
		private readonly ?CollabInfo $collabInfo = null,
	) {}

	public function getFixedChatIds(): array
	{
		return $this->fixedChatIds;
	}

	public static function getRestEntityName(): string
	{
		return 'sectionMeta';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'fixedChatIds' => $this->fixedChatIds,
			'collabInfo' => $this->collabInfo?->toRestFormat(),
		];
	}
}
