<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class AppChatBackfillCursorStorage
{
	private const OPTION = 'appChat.backfillCursor';

	public function __construct(
		private readonly ModuleOptions $options = new ModuleOptions(),
	) {}

	public function load(): AppChatBackfillCursor
	{
		try
		{
			$state = Json::decode($this->options->get(self::OPTION, '{}'));

			return is_array($state)
				? AppChatBackfillCursor::fromArray($state)
				: new AppChatBackfillCursor();
		}
		catch (\Throwable)
		{
			return new AppChatBackfillCursor();
		}
	}

	public function save(AppChatBackfillCursor $cursor): void
	{
		$this->options->set(self::OPTION, Json::encode($cursor->toArray()));
	}
}
