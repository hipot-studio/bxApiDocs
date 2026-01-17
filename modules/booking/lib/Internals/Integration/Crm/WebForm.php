<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Crm\Service\WebForm\Scenario\BaseScenario;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Preset;
use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ORM\Fields\ExpressionField;

class WebForm
{
	public static function isAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	public static function canEdit(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions()->webForm()->canEdit();
	}

	public static function getPresets(): array
	{
		$result = [];

		if (!self::isAvailable())
		{
			return $result;
		}

		$presets = Preset::getByIds([
			BaseScenario::SCENARIO_BOOKING_AUTO_SELECTION,
			BaseScenario::SCENARIO_BOOKING_ANY_RESOURCE,
			BaseScenario::SCENARIO_BOOKING_MANUAL_SETTINGS,
		]);

		foreach ($presets as $preset)
		{
			$scenario = new BaseScenario($preset['XML_ID']);

			$result[] = [
				'id' => $preset['XML_ID'],
				'link' => '/crm/webform/edit/0/?SCENARIO_ID=' . $preset['XML_ID'],
				'title' => $scenario->getTitle(),
				'description' => $scenario->getDescription(),
			];
		}

		return $result;
	}

	public static function getFormsListLink(): string
	{
		if (!self::isAvailable())
		{
			return '';
		}

		return '/crm/webform/?IS_BOOKING_FORM=Y&apply_filter=Y';
	}

	public static function getFormsCount(): int
	{
		if (!self::isAvailable())
		{
			return 0;
		}

		return (int)FormTable::getDefaultTypeList([
			'select' => [
				new ExpressionField('CNT', 'COUNT(1)'),
			],
			'filter' => [
				'=IS_BOOKING_FORM' => 1,
				'=ACTIVE' => 1,
			],
		])->fetch()['CNT'];
	}
}
