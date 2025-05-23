<?
/**
 * This class is for internal use only, not a part of public API.
 * It can be changed at any time without notification.
 * 
 * @access private
 */

namespace Bitrix\Tasks\Integration;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use \Bitrix\Tasks\Util;
use \Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit;

abstract class Bitrix24 extends \Bitrix\Tasks\Integration
{
	const MODULE_NAME = 'bitrix24';

	public static function isFeatureEnabledByTrial(string $featureName): bool
	{
		if(!static::includeModule())
		{
			return false;
		}

		return (
			Feature::isFeatureEnabled($featureName)
			&& array_key_exists($featureName, Feature::getTrialFeatureList())
		);
	}

	public static function getSettingsURL()
	{
		if(!static::includeModule())
		{
			return '';
		}

		return \CBitrix24::PATH_CONFIGS;
	}

	public static function getScheduleUrl(): string
	{
		$url = static::getSettingsURL();
		if ('' === $url)
		{
			return '';
		}

		return $url . '?page=schedule';
	}

	public static function checkToolAvailable($toolName)
	{
		if(!static::includeModule()) // box installation, say yes
		{
			return true;
		}

		return \CBitrix24BusinessTools::isToolAvailable(Util\User::getId(), $toolName);
	}

	public static function checkFeatureEnabled($featureName)
	{
		if (!static::includeModule()) // box installation, say yes
		{
			return true;
		}

		if (
			Limit\TaskLimit::isRelatedWithFeature($featureName)
			&& Limit\TaskLimit::isLimitExist()
			&& !Limit\TaskLimit::isLimitExceeded()
		)
		{
			return true;
		}

		if(Feature::isFeatureEnabled($featureName)) // already payed, or trial is on = yes
		{
			return true;
		}

		return false;
	}

	public static function isLicensePaid()
	{
		if(!static::includeModule()) // box installation is like a free license in terms of bitrix24
		{
			return true;
		}

		return \CBitrix24::isLicensePaid();
	}

	public static function getCurrentLicenseType()
	{
		if(!static::includeModule()) // box installation is like a free license in terms of bitrix24
		{
			return true;
		}

		return \CBitrix24::getLicenseType();
	}

	public static function isLicenseShareware()
	{
		if(!static::includeModule()) // box installation is not a shareware, its like a "freeware" in terms of bitrix24
		{
			return false;
		}

		$type = \CBitrix24::getLicenseType();

		// todo: could be more custom licenses
		return $type == 'nfr' || $type == 'bis_inc' || $type == 'edu' || $type == 'startup';
	}

	/**
	 * Get URL for "Choose a Bitrix24 plan" page.
	 *
	 * @return string
	 */
	public static function getLicenseListPageUrl()
	{
		if (!static::includeModule())
		{
			return '';
		}

		return \CBitrix24::PATH_LICENSE_ALL;
	}

	/**
	 * Get variable value.
	 *
	 * @param $name - Name of variable
	 * @return mixed|null
	 */
	public static function getVariable($name)
	{
		if (!static::includeModule())
		{
			return null;
		}

		return Feature::getVariable($name);
	}

	/**
	 * @param array $params
	 * @return array|null
	 */
	public static function prepareStubInfo(array $params): ?array
	{
		if (static::includeModule() && method_exists('CBitrix24', 'prepareStubInfo'))
		{
			$title = $params['TITLE'] ?? '';
			$content = $params['CONTENT'] ?? '';

			$replacements = $params['REPLACEMENTS'];
			$replacements = (isset($replacements) && is_array($replacements) ? $replacements : []);

			if (!empty($replacements))
			{
				$search = array_keys($replacements);
				$replace = array_values($replacements);

				$title = str_replace($search, $replace, $title);
				$content = str_replace($search, $replace, $content);
			}

			$licenseAllButtonClass = !empty($params['GLOBAL_SEARCH']) ? 'ui-btn ui-btn-xs ui-btn-light-border' : 'success';
			$licenseDemoButtonClass = !empty($params['GLOBAL_SEARCH']) ? 'ui-btn ui-btn-xs ui-btn-light' : '';
			$buttons = [
				['ID' => \CBitrix24::BUTTON_LICENSE_ALL, 'CLASS_NAME' => $licenseAllButtonClass],
				['ID' => \CBitrix24::BUTTON_LICENSE_DEMO, 'CLASS_NAME' => $licenseDemoButtonClass],
			];
			$parameters = [
				'ANALYTICS_LABEL' => 'TASK_FILTER_LIMITS',
			];
			$parameters = !empty($params['GLOBAL_SEARCH']) ? [] : $parameters;

			return \CBitrix24::prepareStubInfo($title, $content, $buttons, $parameters);
		}

		return null;
	}
}