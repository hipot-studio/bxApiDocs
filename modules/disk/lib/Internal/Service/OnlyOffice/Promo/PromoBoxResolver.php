<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\TariffGroupResolverInterface;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Main\License;

readonly class PromoBoxResolver extends PromoResolver
{
	protected const LIMIT_SLIDER_EXTENDABLE_TARIFFS = 'limit_v2_disk_onlyoffice_edit_box';
	protected const LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOST = 'limit_v2_disk_onlyoffice_edit_box_boost';
	protected const LIMIT_SLIDER_OWN_SERVER = 'limit_disk_onlyoffice_own_server_box';
	protected const LIMIT_SLIDER_OWN_SERVER_BOOST = 'limit_disk_onlyoffice_own_server_box_boost';
	protected const LIMIT_LINK_OWN_SERVER_RU = 'https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=48&LESSON_ID=33242';

	/**
	 * @param Environment $environment
	 * @param TariffGroupResolverInterface $tariffGroupResolver
	 * @param BaasSessionBoostService $sessionBoostService
	 * @param CustomServerAvailabilityProvider $customServerAvailabilityProvider
	 * @param License $license
	 */
	public function __construct(
		Environment $environment,
		protected TariffGroupResolverInterface $tariffGroupResolver,
		protected BaasSessionBoostService $sessionBoostService,
		protected CustomServerAvailabilityProvider $customServerAvailabilityProvider,
		protected License $license,
	)
	{
		parent::__construct($environment);
	}

	/**
	 * @return PromoDto|null
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
			TariffGroup::Extendable => $this->getPromoForExtendable(),
			TariffGroup::LargeEnterprise => $this->getPromoForLargeEnterprise(),
			default => null,
		};
	}

	/**
	 * @return PromoDto
	 */
	protected function getPromoForExtendable(): PromoDto
	{
		$code =
			$this->sessionBoostService->isAvailable()
				? static::LIMIT_SLIDER_EXTENDABLE_TARIFFS_WITH_BOOST
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
			return new PromoDto(PromoType::SliderWithPopup, static::LIMIT_SLIDER_OWN_SERVER_BOOST);
		}

		if ($this->customServerAvailabilityProvider->isAvailableForBuy())
		{
			return new PromoDto(PromoType::SliderWithPopup, static::LIMIT_SLIDER_OWN_SERVER);
		}

		if ($this->customServerAvailabilityProvider->isAvailableForUse())
		{
			return new PromoDto(
				type: PromoType::Link,
				params: [
					'url' => $this->getLinkForOwnServer(),
				],
			);
		}

		return new PromoDto(
			type: PromoType::FormWithPopup,
			params: $this->getFeedbackFormParams(),
		);
	}

	/**
	 * @return string
	 */
	protected function getLinkForOwnServer(): string
	{
		return match ($this->license->getRegion()) {
			default => static::LIMIT_LINK_OWN_SERVER_RU,
		};
	}
}
