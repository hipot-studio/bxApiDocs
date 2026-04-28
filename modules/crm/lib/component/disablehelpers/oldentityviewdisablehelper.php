<?php

namespace Bitrix\Crm\Component\DisableHelpers;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\LayoutSettings;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

final class OldEntityViewDisableHelper extends BaseDisableHelper
{
	public const LAST_TIME_SHOWN_FIELD = 'old_layout_disable_alert_last_time_shown_date';
	public const LAST_TIME_SHOWN_OPTION_NAME = 'timestamp';
	private const DISABLE_DATE = 'old_layout_disable_date';
	private const DAYS_TO_NOTIFY_AGAIN = 7;

	public function getJsParams(array $context = []): array
	{
		return [
			'contentName' => AlertContent::OLD_ENTITY_DISABLE->value,
			'contentOptions' => [
				'daysUntilDisable' => $this->getDaysLeftUntilDisable(),
				'isAdmin' => Container::getInstance()->getUserPermissions()->isCrmAdmin(),
				'lastTimeShownField' => self::LAST_TIME_SHOWN_FIELD,
				'lastTimeShownOptionName' => self::LAST_TIME_SHOWN_OPTION_NAME,
				'previewHref' => $this->getPreviewHref($context),
			],
		];
	}

	public function canShowAlert(): bool
	{
		if (LayoutSettings::getCurrent()->isSliderEnabled())
		{
			return false;
		}

		$daysSinceLastTimeShown = $this->getDaysSinceLastTimeShown(
			self::LAST_TIME_SHOWN_FIELD,
			self::LAST_TIME_SHOWN_OPTION_NAME,
		);

		if ($daysSinceLastTimeShown === null)
		{
			return true;
		}

		return $daysSinceLastTimeShown >= self::DAYS_TO_NOTIFY_AGAIN;
	}

	public static function migrateToNewLayout(): void
	{
		if (!LayoutSettings::getCurrent()->isSliderEnabled())
		{
			self::setNewCardLayout();
		}

		self::deleteUnusedOptions();
	}

	private function getDaysLeftUntilDisable(): int
	{
		$disableTimestamp = Option::get('crm', self::DISABLE_DATE, null);

		if ($disableTimestamp === null)
		{
			return 0;
		}

		$disableDate = DateTime::createFromTimestamp($disableTimestamp);
		$currentDate = (new DateTime())->toUserTime();

		return ($disableDate->getTimestamp() > $currentDate->getTimestamp())
			? ($currentDate->getDiff($disableDate))->days
			: 0
		;
	}

	private function getPreviewHref(array $context): string
	{
		if (!isset($context['ENTITY_TYPE_ID'], $context['ENTITY_ID']))
		{
			return '';
		}

		$entityTypeId = (int)$context['ENTITY_TYPE_ID'];
		$entityId = (int)$context['ENTITY_ID'];

		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return '';
		}

		$urlString = Container::getInstance()->getRouter()->getItemDetailUrl($entityTypeId, $entityId);
		if ($urlString === null)
		{
			return '';
		}

		$urlString->setPath(str_replace('/show/', '/details/', $urlString));

		$params = [
			'FORCE_READONLY' => 'Y',
		];
		$urlString->addParams($params);

		return $urlString;
	}

	private static function setNewCardLayout(): void
	{
		LayoutSettings::getCurrent()->enableSlider(true);
	}

	private static function deleteUnusedOptions(): void
	{
		Option::delete('crm', ['name' => self::DISABLE_DATE]);
		\CUserOptions::DeleteOptionsByName('crm', self::LAST_TIME_SHOWN_FIELD);
	}
}
