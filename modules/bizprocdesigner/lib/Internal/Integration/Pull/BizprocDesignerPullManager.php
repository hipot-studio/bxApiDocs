<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Pull;

use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

class BizprocDesignerPullManager
{
	private const MODULE_ID = 'bizprocdesigner';

	public static function OnGetDependentModule(): array
	{
		return [
			'MODULE_ID' => self::MODULE_ID,
			'USE' => ['PUBLIC_SECTION'],
		];
	}

	private function isPullModuleAvailable(): bool
	{
		try
		{
			return Loader::includeModule('pull');
		}
		catch (LoaderException $e)
		{
			return false;
		}
	}

	/**
	 * @param BizprocDesignerPullEvent $event
	 * @param array $params
	 *
	 * @return bool
	 */
	public function sendEvent(int $recipientId, BizprocDesignerPullEvent $event, array $params = []): bool
	{
		if (!$this->isPullModuleAvailable())
		{
			return false;
		}

		return \CPullStack::AddByUser(
			$recipientId,
			$this->buildParams($event, $params)
		);
	}

	private function buildParams(BizprocDesignerPullEvent $event, array $params = []): array
	{
		$params['module_id'] = self::MODULE_ID;
		$params['eventName'] = $event->value;

		$defaultParams = [
			'module_id' => $params['module_id'],
			'command' => $params['eventName'],
			'params' => $params,
		];

		return array_merge($defaultParams, $params);
	}
}
