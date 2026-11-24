<?php
namespace Bitrix\Pull;

class Push
{
	static $types = null;
	private static ?array $sources = null;
	static $config = array();
	protected static array $explodedOptionIdsStaticCache = [];

	public static function add($users, $parameters)
	{
		unset($parameters['command']);
		unset($parameters['params']);
		return \Bitrix\Pull\Event::add($users, $parameters);
	}

	public static function send()
	{
		return \Bitrix\Pull\Event::send();
	}

	public static function getTypes()
	{
		if (is_array(self::$types))
		{
			return self::$types;
		}

		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return Array();
		}

		$notifySchema = \CIMNotifySchema::GetNotifySchema();

		$result = Array();
		foreach ($notifySchema as $moduleId => $module)
		{
			if ($module['NAME'] == '')
			{
				$info = \CModule::CreateModuleObject($moduleId);
				$name= $info->MODULE_NAME;
			}
			else
			{
				$name = $module['NAME'];
			}

			$types = Array();
			foreach ($module['NOTIFY'] ?? [] as $notifyType => $notifyConfig)
			{
				if (!$notifyConfig['PUSH'] && $notifyConfig['DISABLED']['PUSH'])
				{
					continue;
				}
				$types[$notifyType] = Array(
					'NAME' => $notifyConfig['NAME'],
					'TYPE' => $notifyType,
					'DISABLED' => (bool)$notifyConfig['DISABLED']['PUSH'],
					'DEFAULT' => (bool)$notifyConfig['PUSH'],
				);
			}
			if (empty($types))
			{
				continue;
			}

			$result[$moduleId] = Array(
				'NAME' => $name,
				'MODULE_ID' => $moduleId,
				'TYPES' => $types
			);
		}

		self::$types = $result;

		return $result;
	}

	public static function getSources(): array
	{
		if (self::$sources !== null)
		{
			return self::$sources;
		}
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return [];
		}

		return self::$sources = self::normalizeSources(\CIMNotifySchema::GetNotifySchema());
	}

	public static function normalizeSources(array $notifySchema): array
	{
		$result = [];
		$position = 0;
		foreach ($notifySchema as $schema)
		{
			$source = $schema['PRODUCT_SOURCE'] ?? null;
			if (!self::isValidProductSource($source))
			{
				continue;
			}

			$id = $source['ID'];
			if (isset($result[$id]))
			{
				continue;
			}
			$result[$id] = [
				'sort' => $source['SORT'] ?? PHP_INT_MAX,
				'position' => $position++,
				'source' => [
					'id' => $id,
					'label' => $source['NAME'],
					'description' => $source['DESCRIPTION'] ?? null,
					'icon' => [
						'type' => $source['ICON']['TYPE'],
						'value' => $source['ICON']['VALUE'],
					],
				],
			];
		}

		uasort($result, static fn(array $left, array $right): int =>
			[$left['sort'], $left['position']] <=> [$right['sort'], $right['position']]
		);

		return array_column($result, 'source');
	}

	private static function isValidProductSource(mixed $source): bool
	{
		if (!is_array($source))
		{
			return false;
		}
		$id = $source['ID'] ?? null;
		$name = $source['NAME'] ?? null;
		$description = $source['DESCRIPTION'] ?? null;
		$sort = $source['SORT'] ?? null;
		$icon = $source['ICON'] ?? null;
		if (
			!is_string($id)
			|| preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id) !== 1
			|| !is_string($name)
			|| trim($name) === ''
			|| (!is_null($description) && !is_string($description))
			|| (!is_null($sort) && (!is_int($sort) || $sort < 0))
			|| !is_array($icon)
		)
		{
			return false;
		}

		return in_array($icon['TYPE'] ?? null, ['named', 'uri'], true)
			&& is_string($icon['VALUE'] ?? null)
			&& trim($icon['VALUE']) !== '';
	}

	public static function getConfig($userId = null)
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return Array();
		}

		if (is_null($userId) && is_object($GLOBALS['USER']))
		{
			$userId = $GLOBALS['USER']->getId();
		}

		$userId = intval($userId);
		if (!$userId)
		{
			return false;
		}

		if (isset(self::$config[$userId]))
		{
			return self::$config[$userId];
		}

		$pushDisabled = !\Bitrix\Pull\Push::getStatus($userId);

		$userOptions = \CIMSettings::Get($userId)[\CIMSettings::NOTIFY] ?? [];

		$result = Array();
		foreach ($userOptions as $optionId => $optionValue)
		{
			self::$explodedOptionIdsStaticCache[$optionId] ??= explode('|', $optionId);
			[$clientId, $moduleId, $type] = self::$explodedOptionIdsStaticCache[$optionId];

			if ($clientId != \CIMSettings::CLIENT_PUSH)
			{
				continue;
			}

			$result[$moduleId][$type] = (bool)$optionValue;
		}

		$notifySchema = \CIMNotifySchema::GetNotifySchema();

		foreach ($notifySchema as $moduleId => $module)
		{
			foreach ($module['NOTIFY'] as $notifyType => $notifyConfig)
			{
				if ($pushDisabled)
				{
					$result[$moduleId][$notifyType] = false;
					continue;
				}

				if (!$notifyConfig['PUSH'] && $notifyConfig['DISABLED']['PUSH'])
				{
					continue;
				}

				if (!isset($result[$moduleId][$notifyType]) || $notifyConfig['DISABLED']['PUSH'])
				{
					$result[$moduleId][$notifyType] = (bool)$notifyConfig['PUSH'];
				}
			}
		}

		self::$config[$userId] = $result;

		return $result;
	}

	public static function setConfig($config, $userId = null)
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		if (!is_array($config))
		{
			return false;
		}

		if (is_null($userId) && is_object($GLOBALS['USER']))
		{
			$userId = $GLOBALS['USER']->getId();
		}
		$userId = intval($userId);
		if ($userId <= 0)
		{
			return false;
		}

		$types = self::getTypes();
		$userConfig = self::getConfig($userId);
		$userOptions = \CIMSettings::Get($userId)[\CIMSettings::NOTIFY] ?? [];

		$needUpdate = false;
		foreach ($types as $moduleId => $module)
		{
			foreach ($module['TYPES'] as $typeId => $type)
			{
				if (isset($config[$moduleId][$typeId]))
				{
					$needUpdate = true;
					$userConfig[$moduleId][$typeId] = (bool)$config[$moduleId][$typeId];
				}
				if ($type['DEFAULT'] == $userConfig[$moduleId][$typeId])
				{
					unset($userOptions['push|'.$moduleId.'|'.$typeId]);
				}
				else
				{
					$userOptions['push|'.$moduleId.'|'.$typeId] = $userConfig[$moduleId][$typeId];
				}
			}
		}

		if ($needUpdate)
		{
			\CIMSettings::Set(\CIMSettings::NOTIFY, $userOptions, $userId);
			\CIMSettings::ClearCache($userId);
			unset(self::$config[$userId]);
		}

		return true;
	}

	public static function setConfigTypeStatus($moduleId, $typeId, $status, $userId = null)
	{
		return self::setConfig(Array($moduleId => Array($typeId => $status)), $userId);
	}

	public static function getConfigTypeStatus($moduleId, $typeId, $userId = null)
	{
		$config = self::getConfig($userId);
		return isset($config[$moduleId][$typeId])? $config[$moduleId][$typeId]: true;
	}

	public static function getStatus($userId = null)
	{
		if (!\CPullOptions::GetPushStatus())
		{
			return null;
		}

		if (is_null($userId) && is_object($GLOBALS['USER']))
		{
			$userId = $GLOBALS['USER']->getId();
		}
		$userId = intval($userId);
		if (!$userId)
		{
			return false;
		}

		return (bool)\CUserOptions::GetOption('pull', 'push_status', true, $userId);
	}

	public static function setStatus($status, $userId = null)
	{
		if (!\CPullOptions::GetPushStatus())
		{
			return null;
		}

		if (is_null($userId) && is_object($GLOBALS['USER']))
		{
			$userId = $GLOBALS['USER']->getId();
		}
		$userId = intval($userId);
		if (!$userId)
		{
			return false;
		}

		$status = $status === false? false: true;

		return (bool)\CUserOptions::SetOption('pull', 'push_status', $status, false, $userId);
	}
}
