<?

namespace Bitrix\Seo\Region;

use Bitrix\Main\Loader;

class VkAvailability
{
	private const ALLOWED_ZONES = ['ru', 'kz', 'by', 'uz'];

	public static function isVkAllowedZone(?string $zone): bool
	{
		return is_string($zone) && $zone !== '' && in_array($zone, self::ALLOWED_ZONES, true);
	}

	public static function isAvailable(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return self::isVkAllowedZone(\CBitrix24::getPortalZone());
	}

	public static function isVkEngineCode(string $code): bool
	{
		return strpos($code, '.vkontakte') !== false || strpos($code, '.vkads') !== false;
	}
}
