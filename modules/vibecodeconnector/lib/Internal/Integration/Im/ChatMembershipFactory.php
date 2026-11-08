<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\ImChatMembersApi;

final class ChatMembershipFactory
{
	public static function createRelations(): ChatRelations
	{
		return self::usesPublicApi() ? new ChatRelations() : new CompatChatRelations();
	}

	public static function createWriter(): ChatMembershipWriter
	{
		return self::usesPublicApi() ? new ChatMembershipWriter() : new CompatChatMembershipWriter();
	}

	private static function usesPublicApi(): bool
	{
		return Feature::isEnabled(ImChatMembersApi::class);
	}
}
