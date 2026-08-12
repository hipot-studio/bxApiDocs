<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Pull;

use Bitrix\Im\V2\Pull\EventType;

class ReadAllByRecentSection extends ReadAll
{
	private string $recentSection;
	private ?int $parentId;

	public function __construct(int $userId, string $recentSection, ?int $parentId)
	{
		$this->recentSection = $recentSection;
		$this->parentId = $parentId;
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::ReadAllByRecentSection;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'recentSection' => $this->recentSection,
			'parentChatId' => $this->parentId,
		];
	}
}
