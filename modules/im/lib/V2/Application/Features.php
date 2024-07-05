<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\Im\Call\Integration\Zoom;
use Bitrix\Im\Integration\Disk\Documents;
use Bitrix\Im\Settings;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\ImBot\Bot\Giphy;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class Features
{
	public function __construct(
		public readonly bool $chatV2,
		public readonly bool $chatDepartments,
		public readonly bool $copilot,
		public readonly bool $sidebarLinks,
		public readonly bool $sidebarFiles,
		public readonly bool $sidebarBriefs,
		public readonly bool $zoomActive,
		public readonly bool $zoomAvailable,
		public readonly bool $giphyAvailable,
	){}

	public static function get(): self
	{


		return new self(
			!Settings::isLegacyChatActivated(),
			Structure::isSyncAvailable(),
			CopilotChat::isAvailable(),
			Option::get('im', 'im_link_url_migration', 'N') === 'Y',
			Option::get('im', 'im_link_file_migration', 'N') === 'Y',
			Documents::getResumesOfCallStatus() === Documents::ENABLED,
			Zoom::isActive(),
			Zoom::isAvailable(),
			self::isGiphyAvailable(),
		);
	}

	private static function isGiphyAvailable(): bool
	{
		return Loader::includeModule('imbot')
			&& method_exists(Giphy::class, 'isAvailable')
			&& Giphy::isAvailable()
		;
	}
}