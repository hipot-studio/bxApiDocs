<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

use Bitrix\Landing\Site\Type;

enum Tools: string
{
	case Ai = 'ai';
	case Site = 'site';
	case Shop = 'shop';
	case Knowledge = 'kb';
	case Vibe = 'vibe';
	case CrmForms = 'crm_forms';

	public static function getBySiteType(string $siteType): Tools
	{
		return match ($siteType)
		{
			'STORE' => self::Shop,
			Type::SCOPE_CODE_KNOWLEDGE, Type::SCOPE_CODE_GROUP => self::Knowledge,
			Type::SCOPE_CODE_MAINPAGE => self::Vibe,
			default => self::Site,
		};
	}
}
