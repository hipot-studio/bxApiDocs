<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Common\FormatConverter;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseFilter;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

/**
 * Opt-in decoration of the im-recent-v2 chat items: a subtitle by the extended chat type,
 * a letter avatar for a chat without a picture and the tag colors by the type group.
 * Items are neither added, removed nor reordered.
 */
final class RecentChatTypeDataFilter extends BaseFilter
{
	public const ID = 'im.recentChatTypeDataFilter';

	private const ENTITY_TYPE_CHAT = 'im-chat';
	private const PHRASE_PREFIX = 'IM_ENTITY_SELECTOR_RECENT_CHAT_TYPE_';
	private const AVATAR_FALLBACK_COLOR = '#909090';
	private const TAG_COLORS_CHANNEL = ['bgColor' => '#EAF6C3', 'textColor' => '#8DBB00'];
	private const TAG_COLORS_COLLAB = ['bgColor' => '#ade7e4', 'textColor' => '#207976'];
	private const TAG_COLORS_CHAT = ['bgColor' => '#D3F4FF', 'textColor' => '#11A9D9'];

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER'] instanceof \CUser && $GLOBALS['USER']->isAuthorized();
	}

	public function apply(array $items, Dialog $dialog): void
	{
		foreach ($items as $item)
		{
			if (!($item instanceof Item) || $item->isHidden() || $item->getEntityType() !== self::ENTITY_TYPE_CHAT)
			{
				continue;
			}

			$chat = $item->getCustomData()->get('chat');
			if (!is_array($chat))
			{
				continue;
			}

			$type = $this->getType($chat);

			$subtitle = $this->getSubtitle($type);
			if ($subtitle !== null)
			{
				$item->setSubtitle($subtitle);
			}

			$avatar = $item->getAvatar();
			if ($avatar === null || $avatar === '')
			{
				$item->setAvatar($this->makeLetterAvatar($chat));
			}

			foreach ($this->getTagColors($type) as $option => $color)
			{
				$item->getTagOptions()->set($option, $color);
			}
		}
	}

	private function getType(array $chat): ?ExtendedType
	{
		$type = $chat['type'] ?? null;
		if (!is_string($type) || $type === '')
		{
			return null;
		}

		return ExtendedType::tryFrom(FormatConverter::toUpperSnakeCase($type));
	}

	private function getSubtitle(?ExtendedType $type): ?string
	{
		$phraseCode = match ($type)
		{
			ExtendedType::Chat, ExtendedType::OpenChat, ExtendedType::General => 'GROUP_CHAT',
			ExtendedType::Channel => 'CLOSED_CHANNEL',
			ExtendedType::OpenChannel, ExtendedType::GeneralChannel => 'OPEN_CHANNEL',
			ExtendedType::Collab => 'COLLAB',
			ExtendedType::Sonet => 'SONET_GROUP',
			ExtendedType::Tasks => 'TASKS',
			ExtendedType::Crm => 'CRM',
			ExtendedType::Calendar => 'CALENDAR',
			ExtendedType::Videoconference => 'VIDEOCONF',
			ExtendedType::Call => 'CALL',
			ExtendedType::Announcement => 'ANNOUNCEMENT',
			default => null,
		};

		if ($phraseCode === null)
		{
			return null;
		}

		$subtitle = Loc::getMessage(self::PHRASE_PREFIX . $phraseCode);

		return is_string($subtitle) && $subtitle !== '' ? $subtitle : null;
	}

	private function getTagColors(?ExtendedType $type): array
	{
		return match ($type)
		{
			ExtendedType::Channel, ExtendedType::OpenChannel, ExtendedType::GeneralChannel => self::TAG_COLORS_CHANNEL,
			ExtendedType::Collab => self::TAG_COLORS_COLLAB,
			default => self::TAG_COLORS_CHAT,
		};
	}

	private function makeLetterAvatar(array $chat): string
	{
		$color = $chat['color'] ?? null;
		if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/iD', $color))
		{
			$color = self::AVATAR_FALLBACK_COLOR;
		}

		$svg
			= '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">'
			. '<circle cx="20" cy="20" r="20" fill="' . $color . '"/>';

		$letter = $this->getLetter($chat['name'] ?? null);
		if ($letter !== null)
		{
			$svg
				.= '<text x="20" y="20" dy="0.35em" text-anchor="middle" fill="#FFFFFF"'
				. ' font-family="Helvetica, Arial, sans-serif" font-size="18">'
				. htmlspecialchars($letter, ENT_XML1 | ENT_QUOTES)
				. '</text>';
		}

		$svg .= '</svg>';

		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
	}

	private function getLetter(mixed $name): ?string
	{
		if (!is_string($name))
		{
			return null;
		}

		$firstSymbol = mb_substr(trim($name), 0, 1);
		if ($firstSymbol === '' || !preg_match('/^[\p{L}\p{N}]$/u', $firstSymbol))
		{
			return null;
		}

		return mb_strtoupper($firstSymbol);
	}
}
