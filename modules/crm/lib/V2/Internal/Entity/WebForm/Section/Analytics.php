<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Analytics
{
	public const FIELD_GOOGLE_ANALYTICS_ID = 'googleAnalyticsId';
	public const FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED = 'googleAnalyticsPageViewEnabled';
	public const FIELD_YANDEX_METRICA_ID = 'yandexMetricaId';

	public function __construct(
		public ?string $googleAnalyticsId = null,
		public ?bool $googleAnalyticsPageViewEnabled = null,
		public ?string $yandexMetricaId = null,
	)
	{
	}
}
