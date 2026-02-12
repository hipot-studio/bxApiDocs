<?php

namespace Bitrix\Rest\Configuration;

use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Rest\Configuration\Core\OwnerEntityTable;

class Controller
{
	const ON_REST_APP_CONFIGURATION_CLEAR = 'OnRestApplicationConfigurationClear';
	const ON_REST_APP_CONFIGURATION_ENTITY = 'OnRestApplicationConfigurationEntity';
	const ON_REST_APP_CONFIGURATION_EXPORT = 'OnRestApplicationConfigurationExport';
	const ON_REST_APP_CONFIGURATION_IMPORT = 'OnRestApplicationConfigurationImport';
	const ON_REST_APP_CONFIGURATION_FINISH = 'OnRestApplicationConfigurationFinish';

	/**
	 *	array value: [a-zA-Z0-9_]
	 */
	public static function getEntityCodeList()
	{
		$result = [];

		$event = new Event('rest', static::ON_REST_APP_CONFIGURATION_ENTITY);
		EventManager::getInstance()->send($event);
		foreach ($event->getResults() as $eventResult)
		{
			$codeList = $eventResult->getParameters();
			if (is_array($codeList))
			{
				$result = array_merge($result, $codeList);
			}
		}
		asort($result);

		return array_keys($result);
	}

	public static function callEventExport($manifestCode, $code, $step = 0, $next = '', $itemCode = '', $contextUser = false)
	{
		$result = [];
		if ($manifestCode == '')
		{
			return $result;
		}

		$manifest = Manifest::get($manifestCode);
		if (!is_null($manifest))
		{
			$setting = new Setting($contextUser);
			$runtimeData = $setting->get(Setting::SETTING_ACTION_ADDITIONAL_OPTION) ?? [];

			$event = new Event(
				'rest',
				static::ON_REST_APP_CONFIGURATION_EXPORT,
				[
					'CODE' => $code,
					'STEP' => $step,
					'NEXT' => $next,
					'MANIFEST' => $manifest,
					'ITEM_CODE' => $itemCode,
					'SETTING' => $setting->get(Setting::SETTING_MANIFEST),
					'ADDITIONAL_OPTION' => $runtimeData,
					'USER_ID' => $setting->get(Setting::SETTING_USER_ID) ?? 0,
				]
			);
			EventManager::getInstance()->send($event);
			foreach ($event->getResults() as $eventResult)
			{
				$parameters = $eventResult->getParameters();
				$result[] = [
					'FILE_NAME' => $parameters['FILE_NAME'],
					'CONTENT' => $parameters['CONTENT'],
					'FILES' => $parameters['FILES'] ?? null,
					'NEXT' => $parameters['NEXT'] ?? null,
					'ERROR_MESSAGES' => $parameters['ERROR_MESSAGES'] ?? null,
					'ERROR_ACTION' => $parameters['ERROR_ACTION'] ?? null,
					'ERROR_EXCEPTION' => $parameters['ERROR_EXCEPTION'] ?? null,
				];
				if (!empty($parameters['ADDITIONAL_OPTION']) && is_array($parameters['ADDITIONAL_OPTION']))
				{
					$newRuntimeData = array_replace_recursive(
						($newRuntimeData ?? $runtimeData),
						$parameters['ADDITIONAL_OPTION']
					);
				}
			}
			if (isset($newRuntimeData))
			{
				$setting->set(
					Setting::SETTING_ACTION_ADDITIONAL_OPTION,
					$newRuntimeData,
				);
			}
		}

		return $result;
	}

	public static function callEventClear($data)
	{
		$result = [
			'NEXT' => false
		];

		$settingSession = new Setting($data['CONTEXT_USER']);
		$runtimeData = $settingSession->get(Setting::SETTING_ACTION_ADDITIONAL_OPTION) ?? [];

		$event = new Event(
			'rest',
			static::ON_REST_APP_CONFIGURATION_CLEAR,
			[
				'CODE' => $data['CODE'],
				'STEP' => $data['STEP'],
				'NEXT' => $data['NEXT'],
				'CONTEXT' => $data['CONTEXT'],
				'CONTEXT_USER' => $data['CONTEXT_USER'],
				'CLEAR_FULL' => $data['CLEAR_FULL'],
				'PREFIX_NAME' => $data['PREFIX_NAME'],
				'MANIFEST_CODE' => $data['MANIFEST_CODE'],
				'IMPORT_MANIFEST' => $data['IMPORT_MANIFEST'],
				'MANIFEST' => Manifest::get($data['MANIFEST_CODE']),
				'SETTING' => $settingSession->get(Setting::SETTING_MANIFEST),
				'ADDITIONAL_OPTION' => $runtimeData,
				'USER_ID' => $settingSession->get(Setting::SETTING_USER_ID) ?? 0,
			]
		);
		EventManager::getInstance()->send($event);
		foreach ($event->getResults() as $eventResult)
		{
			$parameters = $eventResult->getParameters();
			$result = [
				'NEXT' => $parameters['NEXT'] ?? null,
				'ERROR_MESSAGES' => $parameters['ERROR_MESSAGES'] ?? null,
				'ERROR_ACTION' => $parameters['ERROR_ACTION'] ?? null,
				'ERROR_EXCEPTION' => $parameters['ERROR_EXCEPTION'] ?? null
			];

			if (!empty($parameters['OWNER_DELETE']) && is_array($parameters['OWNER_DELETE']))
			{
				OwnerEntityTable::deleteMulti($parameters['OWNER_DELETE']);
			}

			if (!empty($parameters['ADDITIONAL_OPTION']) && is_array($parameters['ADDITIONAL_OPTION']))
			{
				$newRuntimeData = array_replace_recursive(
					($newRuntimeData ?? $runtimeData),
					$parameters['ADDITIONAL_OPTION']
				);
			}
		}
		if (isset($newRuntimeData))
		{
			$settingSession->set(
				Setting::SETTING_ACTION_ADDITIONAL_OPTION,
				$newRuntimeData,
			);
		}

		return $result;
	}

	public static function callEventImport($params)
	{
		$result = [];
		$params['CONTEXT_USER'] = $params['CONTEXT_USER'] ?: false;
		$setting = new Setting($params['CONTEXT_USER']);
		$entityMapping = $setting->get(Setting::SETTING_RATIO);
		$runtimeData = $setting->get(Setting::SETTING_ACTION_ADDITIONAL_OPTION) ?? [];

		$app = $setting->get(Setting::SETTING_APP_INFO);
		if (!empty($app['ID']) && $app['ID'] > 0)
		{
			$owner = $app['ID'];
			$ownerType = OwnerEntityTable::ENTITY_TYPE_APPLICATION;
		}
		else
		{
			$owner = OwnerEntityTable::ENTITY_EMPTY;
			$ownerType = OwnerEntityTable::ENTITY_TYPE_EXTERNAL;
		}

		$event = new Event(
			'rest',
			static::ON_REST_APP_CONFIGURATION_IMPORT,
			[
				'CODE' => $params['CODE'],
				'CONTENT' => $params['CONTENT'],
				'RATIO' => $entityMapping ?? [],
				'CONTEXT' => $params['CONTEXT'],
				'CONTEXT_USER' => $params['CONTEXT_USER'],
				'SETTING' => $setting->get(Setting::SETTING_MANIFEST),
				'USER_ID' => $setting->get(Setting::SETTING_USER_ID) ?? 0,
				'MANIFEST_CODE' => $params['MANIFEST_CODE'],
				'IMPORT_MANIFEST' => $params['IMPORT_MANIFEST'] ?? [],
				'MANIFEST' => Manifest::get($params['MANIFEST_CODE']),
				'ADDITIONAL_OPTION' => $runtimeData,
				'APP_ID' => intVal($owner),
			]
		);

		EventManager::getInstance()->send($event);
		$saveEntityMapping = false;
		foreach ($event->getResults() as $eventResult)
		{
			$parameters = $eventResult->getParameters();
			$result[] = [
				'RATIO' => $parameters['RATIO'] ?? null,
				'ERROR_MESSAGES' => $parameters['ERROR_MESSAGES'] ?? null,
				'ERROR_ACTION' => $parameters['ERROR_ACTION'] ?? null,
				'ERROR_EXCEPTION' => $parameters['ERROR_EXCEPTION'] ?? null
			];

			if (!empty($parameters['RATIO']))
			{
				$saveEntityMapping = true;
				$entityMapping[$params['CODE']] ??= [];
				$entityMapping[$params['CODE']] = array_replace($entityMapping[$params['CODE']], $parameters['RATIO']);
			}
			if (isset($parameters['OWNER_DELETE']) && is_array($parameters['OWNER_DELETE']))
			{
				OwnerEntityTable::deleteMulti($parameters['OWNER_DELETE']);
			}

			if (!empty($parameters['OWNER']))
			{
				OwnerEntityTable::saveMulti($owner, $ownerType, $parameters['OWNER']);
			}

			if (!empty($parameters['ADDITIONAL_OPTION']) && is_array($parameters['ADDITIONAL_OPTION']))
			{
				$newRuntimeData = array_replace_recursive(
					($newRuntimeData ?? $runtimeData),
					$parameters['ADDITIONAL_OPTION']
				);
			}
		}

		if ($saveEntityMapping)
		{
			$setting->set(Setting::SETTING_RATIO, $entityMapping);
		}
		if (isset($newRuntimeData))
		{
			$setting->set(
				Setting::SETTING_ACTION_ADDITIONAL_OPTION,
				$newRuntimeData
			);
		}

		return $result;
	}

	public static function callEventFinish($params)
	{
		$result = [];
		if (!empty($params['MANIFEST_CODE']))
		{
			$params['MANIFEST'] = Manifest::get($params['MANIFEST_CODE']);
		}
		$event = new Event(
			'rest',
			static::ON_REST_APP_CONFIGURATION_FINISH,
			$params
		);
		EventManager::getInstance()->send($event);
		foreach ($event->getResults() as $eventResult)
		{
			$parameters = $eventResult->getParameters();
			$result[] = [
				'CREATE_DOM_LIST' => $parameters['CREATE_DOM_LIST'],
				'ADDITIONAL' => $parameters['ADDITIONAL'],
			];
		}

		return $result;
	}
}
