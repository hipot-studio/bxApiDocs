<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\PromoResolverInterface;
use Bitrix\Main\Security\Random;

abstract readonly class PromoResolver implements PromoResolverInterface
{
	/**
	 * @param Environment $environment
	 */
	public function __construct(
		protected Environment $environment,
	)
	{
	}

	/**
	 * @return array
	 */
	protected function getFeedbackFormParams(): array
	{
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
				'from_domain' => $this->environment->getDomain(),
			],
		];
	}
}
