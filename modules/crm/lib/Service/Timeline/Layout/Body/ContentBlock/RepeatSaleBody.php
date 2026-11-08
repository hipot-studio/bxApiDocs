<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

/**
 * RepeatSale-only redesigned card body.
 *
 * Renders a single custom block (essence hero + moment/potential + action checklist
 * + purchase history) via the JS component 'RepeatSaleBody', registered by the
 * RepeatSale timeline controller. Isolated to the RepeatSale card.
 */
final class RepeatSaleBody extends ContentBlock
{
	public function __construct(private readonly array $data)
	{
	}

	public function getRendererName(): string
	{
		return 'RepeatSaleBody';
	}

	protected function getProperties(): ?array
	{
		return $this->data;
	}
}
