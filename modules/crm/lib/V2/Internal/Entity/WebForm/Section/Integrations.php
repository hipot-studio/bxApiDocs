<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Integrations
{
	public const FIELD_WHATS_APP_ENABLED = 'whatsAppEnabled';
	public const FIELD_ADS = 'ads';

	/**
	 * @param null|array<AdIntegration> $ads
	 */
	public function __construct(
		public ?bool $whatsAppEnabled = null,
		public ?array $ads = null,
	)
	{
	}
}
