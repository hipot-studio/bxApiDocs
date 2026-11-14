<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSource;
use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSourceCode;
use Bitrix\Main\Localization\Loc;

/**
 * The built-in sources available on the portal: by region, the Avito feature, the social domain option and the
 * sender module, with the same rules, colors and domains as the legacy static sources. Their equality is kept
 * by a test.
 *
 * @internal
 */
final class SourceCatalog
{
	private const COLORS = [
		'google' => '#3889db',
		'fb' => '#38659f',
		'instagram' => '#d56c9a',
		'vkads' => '#0077FF',
		'vk' => '#3871ba',
		'yandex' => '#ffce00',
		'1c' => '#fade39',
		'avito' => '#0af',
		'organic' => '',
		'sender-mail' => '#2ebef0',
	];

	private const SOCIAL_REF_DOMAINS = [
		'fb' => ['www.facebook.com', 'facebook.com'],
		'instagram' => ['www.instagram.com', 'instagram.com'],
		'vkads' => ['www.ads.vk.ru', 'ads.vk.ru'],
		'vk' => ['www.vk.ru', 'vk.ru'],
	];

	private const REGION_RESTRICTED_CODES = [BuiltInSourceCode::Vk, BuiltInSourceCode::VkAds];

	public function __construct(
		private readonly TrackingEnvironment $environment = new TrackingEnvironment(),
	)
	{
	}

	/**
	 * @return list<BuiltInSource> in the order of the legacy catalog
	 */
	public function list(): array
	{
		$list = [];
		foreach ($this->availableCodes() as $code)
		{
			$list[] = $this->describe($code);
		}

		return $list;
	}

	public function find(string $code): ?BuiltInSource
	{
		foreach ($this->list() as $source)
		{
			if ($source->code->value === $code)
			{
				return $source;
			}
		}

		return null;
	}

	/**
	 * Whether the source cannot be set up in the zone of the portal, as the UI refuses VKontakte and VK Ads.
	 */
	public function isRegionRestricted(string $code): bool
	{
		$builtInCode = BuiltInSourceCode::tryFrom($code);

		return
			in_array($builtInCode, self::REGION_RESTRICTED_CODES, true)
			&& !$this->environment->isVkAvailable()
		;
	}

	/**
	 * @return list<BuiltInSourceCode>
	 */
	private function availableCodes(): array
	{
		$codes = [BuiltInSourceCode::Google];

		if (!$this->environment->isRussianRegionStrict())
		{
			$codes[] = BuiltInSourceCode::Fb;
			$codes[] = BuiltInSourceCode::Instagram;
		}

		if ($this->environment->isRussianRegion())
		{
			$codes[] = BuiltInSourceCode::VkAds;
			$codes[] = BuiltInSourceCode::Vk;
			$codes[] = BuiltInSourceCode::Yandex;
			$codes[] = BuiltInSourceCode::OneC;
		}

		if ($this->environment->isRussianRegionStrict() && $this->environment->isAvitoEnabled())
		{
			$codes[] = BuiltInSourceCode::Avito;
		}

		$codes[] = BuiltInSourceCode::Organic;

		if ($this->environment->canUseSender())
		{
			$codes[] = BuiltInSourceCode::SenderMail;
		}

		return $codes;
	}

	private function describe(BuiltInSourceCode $code): BuiltInSource
	{
		return new BuiltInSource(
			code: $code,
			defaultName: $this->defaultName($code),
			color: self::COLORS[$code->value],
			refDomains: $this->refDomains($code),
			utmSources: $code === BuiltInSourceCode::SenderMail ? $this->environment->getSenderUtmSources() : null,
			showOnlyExisting: $code === BuiltInSourceCode::OneC,
		);
	}

	/**
	 * @return list<string>|null
	 */
	private function refDomains(BuiltInSourceCode $code): ?array
	{
		if ($code === BuiltInSourceCode::Google || $code === BuiltInSourceCode::Yandex)
		{
			return [];
		}

		if (!isset(self::SOCIAL_REF_DOMAINS[$code->value]))
		{
			return null;
		}

		return $this->environment->isSocialRefDomainUsed() ? self::SOCIAL_REF_DOMAINS[$code->value] : [];
	}

	private function defaultName(BuiltInSourceCode $code): string
	{
		$messageCode = 'CRM_V2_TRACKING_SOURCE_CATALOG_NAME_' . mb_strtoupper(str_replace('-', '_', $code->value));

		return Loc::getMessage($messageCode) ?: $code->value;
	}
}
