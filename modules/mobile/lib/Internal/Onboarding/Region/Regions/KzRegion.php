<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class KzRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('10:00');
		$cfg->setMaxPortalAgeDays(19);

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('📣 Пригласите коллег')
				->setText('Ставьте задачи, используйте в мессенджере, звоните по видео →')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Битрикс24 на компьютере')
				->setText('Оцените все возможности сервиса прямо сейчас →')
			;
		});

		$cfg->onDay(7, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('CRM у вас в смартфоне')
				->setText('Создайте новую сделку прямо с телефона →')
			;
		});

		$cfg->onDay(9, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('Ваш AI-помощник ждет задач')
				->setText('Нейросеть внутри Битрикс24 придумает идеи, распишет задачу, расшифрует звонки 🤖')
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
				->setTitle('Точно ничего не забыли?')
				->setText('Загляните в календарь, чтобы составить расписание на завтра 📅')
			;
		});
	}
}
