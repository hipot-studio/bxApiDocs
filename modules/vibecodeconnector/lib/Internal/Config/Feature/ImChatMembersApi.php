<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Config\Feature;

use Bitrix\Im\V2\Public\Command\Chat\Member\AddMembersCommand;
use Bitrix\Im\V2\Public\Command\Chat\Member\DeleteMembersCommand;
use Bitrix\Im\V2\Public\Provider\Member\ChatMembersProvider;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Loader;

final class ImChatMembersApi extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}

	public function getRequirements(): array
	{
		return [
			static fn (): bool => self::isProvidedByIm(),
		];
	}

	public static function isProvidedByIm(): bool
	{
		return Loader::includeModule('im')
			&& class_exists(AddMembersCommand::class)
			&& class_exists(DeleteMembersCommand::class)
			&& class_exists(ChatMembersProvider::class)
		;
	}
}
