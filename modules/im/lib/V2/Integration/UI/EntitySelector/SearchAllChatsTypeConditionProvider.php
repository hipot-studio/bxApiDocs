<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

class SearchAllChatsTypeConditionProvider
{
	private const EXCLUDED_EXTENDED_TYPES = [
		ExtendedType::Comment->value,
		ExtendedType::Lines->value,
		ExtendedType::System->value,
		ExtendedType::Announcement->value,
		ExtendedType::Videoconference->value,
		ExtendedType::Support24Notifier->value,
		ExtendedType::Support24Question->value,
		ExtendedType::Mail->value,
		ExtendedType::Call->value,
		ExtendedType::NetworkDialog->value,
	];

	public function __construct(private readonly TypeRegistry $typeRegistry)
	{
	}

	public function getCondition(): TypeCondition
	{
		$excludedExtendedTypes = self::EXCLUDED_EXTENDED_TYPES;

		if (!CopilotChat::isHistoryAvailable())
		{
			$excludedExtendedTypes[] = ExtendedType::Copilot->value;
		}

		return new TypeCondition(
			exclude: $this->typeRegistry->getTypesByExtendedTypes($excludedExtendedTypes),
		);
	}
}
