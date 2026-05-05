<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\TariffGroupResolverInterface;

readonly class PromoCloudResolver extends PromoResolver
{
	protected const LIMIT_SLIDER_STARTER_TARIFFS = 'limit_office_no_document';
	protected const LIMIT_SLIDER_EXTENDABLE_TARIFFS = 'limit_v2_disk_onlyoffice_edit';
	protected const LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOSTS = 'limit_v2_disk_onlyoffice_edit_boost';

	/**
	 * @param Environment $environment
	 * @param TariffGroupResolverInterface $tariffGroupResolver
	 * @param BaasSessionBoostService $sessionBoostService
	 */
	public function __construct(
		Environment $environment,
		protected TariffGroupResolverInterface $tariffGroupResolver,
		protected BaasSessionBoostService $sessionBoostService,
	)
	{
		parent::__construct($environment);
	}

	/**
	 * {@inheritDoc}
	 */
	public function resolve(): ?PromoDto
	{
		if (PromoBlocker::shouldBlockPromoForUser())
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

	/**
	 * @return PromoDto
	 */
	protected function getPromoForStarter(): PromoDto
	{
		return new PromoDto(PromoType::Slider, static::LIMIT_SLIDER_STARTER_TARIFFS);
	}

	/**
	 * @return PromoDto
	 */
	protected function getPromoForExtendable(): PromoDto
	{
		$code =
			$this->sessionBoostService->isAvailable()
				? static::LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOSTS
				: static::LIMIT_SLIDER_EXTENDABLE_TARIFFS
		;

		return new PromoDto(PromoType::SliderWithPopup, $code);
	}

	/**
	 * @return PromoDto
	 */
	protected function getPromoForLargeEnterprise(): PromoDto
	{
		if ($this->sessionBoostService->isAvailable())
		{
			return new PromoDto(PromoType::Boost);
		}


		return new PromoDto(PromoType::Form, null, $this->getFeedbackFormParams());
	}
}
