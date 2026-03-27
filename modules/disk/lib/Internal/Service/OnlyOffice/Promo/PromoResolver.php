<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Main\Application;
use Bitrix\Main\Security\Random;

class PromoResolver
{
	private const LIMIT_SLIDER_STARTER_TARIFFS = 'limit_office_no_document';
	private const LIMIT_SLIDER_EXTENDABLE_TARIFFS = 'limit_v2_disk_onlyoffice_edit';
	private const LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOSTS = 'limit_v2_disk_onlyoffice_edit_boost';

	public function __construct(
		private readonly BaasSessionBoostService $sessionBoostService,
		private readonly TariffGroupResolver $tariffGroupResolver,
	)
	{
	}

	public function resolve(): ?PromoDto
	{
		if (self::shouldBlockPromoForUser())
		{
			return null;
		}

		if (!OnlyOfficeHandler::isEnabled())
		{
			return null;
		}

		$tariffGroup = $this->tariffGroupResolver->resolve();

		return match ($tariffGroup) {
			TariffGroup::Starter => $this->getPromoForStarter(),
			TariffGroup::Extendable => $this->getPromoForExtendable(),
			TariffGroup::LargeEnterprise => $this->getPromoForLargeEnterprise(),
			default => null,
		};
	}

	private function getPromoForStarter(): PromoDto
	{
		return new PromoDto(PromoType::Slider, self::LIMIT_SLIDER_STARTER_TARIFFS);
	}

	private function getPromoForExtendable(): PromoDto
	{
		$sliderCode = $this->sessionBoostService->isAvailable() ? self::LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOSTS : self::LIMIT_SLIDER_EXTENDABLE_TARIFFS;

		return new PromoDto(PromoType::Slider, $sliderCode);
	}

	private function getPromoForLargeEnterprise(): PromoDto
	{
		if ($this->sessionBoostService->isAvailable())
		{
			return new PromoDto(PromoType::Boost);
		}


		return new PromoDto(PromoType::Form, null, $this->getFeedbackFormParams());
	}

	private function getFeedbackFormParams(): array
	{
		if (defined('BX24_HOST_NAME'))
		{
			$fromDomain = BX24_HOST_NAME;
		}
		else
		{
			$fromDomain = Application::getInstance()->getContext()->getRequest()->getHttpHost();
		}

		return [
			'id' => Random::getString(20),
			'forms' => [
				[
					'zones' => ['ru', 'kz', 'by', 'uz'],
					'id' => 2996,
					'lang' => 'ru',
					'sec' => '7plkx7',
				],
				[
					'zones' => ['en'],
					'id' => 850,
					'lang' => 'en',
					'sec' => 'c76ugx',
				],
			],
			'presets' => [
				'from_domain' => $fromDomain,
			],
		];
	}

	public static function shouldBlockPromoForUser(): bool
	{
		global $USER;

		return !$USER->isAuthorized() || DocumentEditorUser::isCurrentUserDocumentEditor();
	}
}
