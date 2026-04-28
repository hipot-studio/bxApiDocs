<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Event;

use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Type\Date;

class OnInitSummaryProvidersEvent extends Event
{
	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
	)
	{
		parent::__construct('intranet', 'onInitSummaryProviders', [
			'from' => $this->from,
			'to' => $this->to,
		]);
	}

	public function getFrom(): Date
	{
		return $this->parameters['from'];
	}

	public function getTo(): Date
	{
		return $this->parameters['to'];
	}
	
	public function addProvider(SummaryProviderInterface $provider): void
	{
		$result = new EventResult(EventResult::SUCCESS, ['provider' => $provider]);
		$this->addResult($result);
	}
}