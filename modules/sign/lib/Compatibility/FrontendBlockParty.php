<?php

namespace Bitrix\Sign\Compatibility;


use Bitrix\Sign\Type\BlockParty;

class FrontendBlockParty
{
	public static function getMemberParty(int $blockParty, int $parties): int
	{
		return match ($blockParty)
		{
			BlockParty::COMMON_PARTY => 0,
			BlockParty::LAST_PARTY => $parties,
			default => $parties - 1,
		};
	}

	public static function getBlockParty(int $memberParty, int $parties): int
	{
		if ($memberParty === 0)
		{
			return BlockParty::COMMON_PARTY;
		}

		if ($memberParty === $parties)
		{
			return BlockParty::LAST_PARTY;
		}

		return BlockParty::NOT_LAST_PARTY;
	}

	public static function getRole(int $frontParty): ?string
	{
		return match($frontParty)
		{
			BlockParty::NOT_LAST_PARTY => \Bitrix\Sign\Type\Member\Role::ASSIGNEE,
			BlockParty::LAST_PARTY => \Bitrix\Sign\Type\Member\Role::SIGNER,
			default => null,
		};
	}

	public static function getByRole(?string $role): int
	{
		return match ($role)
		{
			\Bitrix\Sign\Type\Member\Role::ASSIGNEE => BlockParty::NOT_LAST_PARTY,
			\Bitrix\Sign\Type\Member\Role::SIGNER => BlockParty::LAST_PARTY,
			default => BlockParty::COMMON_PARTY,
		};
	}
}
