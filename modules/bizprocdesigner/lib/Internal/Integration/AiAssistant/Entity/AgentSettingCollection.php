<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<AgentSetting>
 */
final class AgentSettingCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof AgentSetting;
	}

	public function toArray(): array
	{
		return array_map(static fn(AgentSetting $item) => $item->toArray(), $this->items);
	}
}