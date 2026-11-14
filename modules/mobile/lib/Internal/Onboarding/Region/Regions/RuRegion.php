<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Internal\Onboarding\Config\JsonCampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Public\Feature\OnboardingActivationSeriesFlag;

/**
 * RU has two schemes, chosen by OnboardingActivationSeriesFlag.
 *
 * With the flag on it runs the activation series: no day map and no values here -
 * schedule, send window, portal age gate, paths, texts and analytics all come from the
 * campaign document (cached JSON with the bundled code default). Enabling the campaign
 * for RU only is what makes the new series RU-only.
 *
 * With the flag off - the default - RU stays on the legacy day map below, so the new
 * series can be rolled back without a release.
 */
class RuRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		// no user in the context: which day map the region walks is a property of the portal -
		// see RegionConfig::isSupported()
		if (Feature::isEnabled(OnboardingActivationSeriesFlag::class, new Feature\Context()))
		{
			$cfg->setCampaignConfig(new JsonCampaignConfigProvider());

			return;
		}

		$cfg->setTimeSend('10:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('📣 Пригласите коллег')
				->setText('Ставьте задачи, общайтесь в мессенджере, звоните по видео и многое другое 👍')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('💻 Битрикс24 на компьютере')
				->setText('Умеет ещё больше! Оцените все возможности сервиса прямо сейчас 👉')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('✨ Ваш ИИ-помощник')
				->setText('CoPilot придумает идеи, распишет задачу, расшифрует звонки. Дайте ему задание! 🤖')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📲 CRM в смартфоне')
				->setText('Продавайте всегда и везде. Сделки, клиенты, документы. Всё здесь 👉')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Есть задача? Поставьте её!')
				->setText('Просто напишите, что нужно сделать. Мы напомним и поможем выполнить вовремя ⏱️')
			;
		});
	}
}
