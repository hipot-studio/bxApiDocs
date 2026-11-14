<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class ByRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('10:00');
		$cfg->setMaxPortalAgeDays(19);

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('👋 Добавьте коллегу')
				->setText('Ставьте задачи, переписывайтесь, звоните по видео →')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('❗ Доступно приложение')
				->setText('Умеет ещё больше! Оцените все возможности сервиса прямо сейчас →')
			;
		});

		$cfg->onDay(7, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('Клиент ожидает ответа')
				->setText('Редактируйте сделки в CRM прямо с телефона →')
			;
		});

		$cfg->onDay(9, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('CoPilot печатает...')
				->setText('Используйте персонального AI-помощника в Битрикс24 →')
			;
		});

		$cfg->onDay(11, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('(1) активная задача')
				->setText('Завершите ее, делегируйте или передвиньте сроки.')
			;
		});

		$cfg->onDay(13, function (DayConfig $day): void {
			$day->setType(PushType::CALENDAR)
				->setTitle('Новое приглашение на встречу')
				->setText('Проверьте свой календарь 📅')
			;
		});
	}
}
