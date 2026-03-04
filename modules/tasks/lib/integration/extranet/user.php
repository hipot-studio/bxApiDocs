<?
/**
 * Class implements all further interactions with "extranet" module
 *
 * This class is for internal use only, not a part of public API.
 * It can be changed at any time without notification.
 *
 * @access private
 */

namespace Bitrix\Tasks\Integration\Extranet;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\IntranetUserCheckService;

final class User extends \Bitrix\Tasks\Integration\Extranet
{
	public static function getAccessible($userId)
	{
		if(!static::includeModule())
		{
			return array();
		}

		return \CExtranet::getMyGroupsUsersSimple(\CExtranet::getExtranetSiteID(), array('userId' => $userId));
	}

	public static function isExtranet($user = 0)
	{
		if(!static::isConfigured())
		{
			return false; // no extranet - no problem, user is NOT AN EXTRANET USER
		}

		if(is_array($user) && !empty($user))
		{
			$result = !(isset($user["UF_DEPARTMENT"]) && isset($user["UF_DEPARTMENT"][0]) && $user["UF_DEPARTMENT"][0] > 0);
		}
		else
		{
			if(!$user)
			{
				$user = \Bitrix\Tasks\Util\User::getId(); // check current
			}

			$result = false;
			$user = (int)$user;
			if($user)
			{
				$intranetUserChecker = Container::getInstance()->get(IntranetUserCheckService::class);
				$result = $intranetUserChecker->isExtranet($user);
			}
		}

		return $result;
	}

	public static function isCollaber(int $userId): bool
	{
		if(!static::isConfigured())
		{
			return false;
		}

		return ServiceContainer::getInstance()
			->getCollaberService()
			->isCollaberById($userId)
		;
	}
}