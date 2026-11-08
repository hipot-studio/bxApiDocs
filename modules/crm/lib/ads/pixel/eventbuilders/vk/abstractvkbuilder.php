<?php

namespace Bitrix\Crm\Ads\Pixel\EventBuilders\Vk;

use Bitrix\Crm\Ads\Pixel\EventBuilders\CrmConversionEventBuilderInterface;
use Bitrix\Seo\Conversion\Vk\Event;
use Bitrix\Seo\LeadAds\Service;

abstract class AbstractVkBuilder implements CrmConversionEventBuilderInterface
{
	public function __construct(
		protected array $entity,
		protected ?LeadOriginResolverInterface $leadOriginResolver = null,
	)
	{
		$this->leadOriginResolver ??= new LeadOriginResolver();
	}

	final public function buildEvents(): array
	{
		$leadId = $this->resolveVkLeadId();
		if ($leadId === null)
		{
			return [];
		}

		return [
			new Event(
				$leadId,
				$this->getEventName(),
				$this->getEventStatus(),
				time(),
				$this->getAmount(),
				$this->getCurrency(),
			),
		];
	}

	abstract protected function getOriginId(): ?string;

	abstract protected function getEventName(): string;

	abstract protected function getEventStatus(): string;

	protected function getAmount(): ?string
	{
		return null;
	}

	protected function getCurrency(): ?string
	{
		return null;
	}

	private function resolveVkLeadId(): ?string
	{
		$originId = $this->getOriginId();
		if ($originId === null || $originId === '')
		{
			return null;
		}

		$parts = explode('/', $originId, 2);
		$prefix = $parts[0];
		$leadId = $parts[1] ?? '';
		if (
			$prefix !== Service::TYPE_VKONTAKTE
			|| $leadId === ''
			|| preg_match('/^[0-9]+$/D', $leadId) !== 1
			|| trim($leadId, '0') === ''
		)
		{
			return null;
		}

		return $leadId;
	}
}
