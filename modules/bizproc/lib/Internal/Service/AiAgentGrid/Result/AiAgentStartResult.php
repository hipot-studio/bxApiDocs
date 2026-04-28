<?php

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result;

use Bitrix\Bizproc\Internal\Event\SetupTemplateCurrentDataEvent;
use Bitrix\Main\Result;

class AiAgentStartResult extends Result
{
	public const SETUP_TEMPLATE_DATA = 'setupTemplateData';

	public function __construct(
		public readonly ?SetupTemplateCurrentDataEvent $setupTemplateEvent = null,
	)
	{
		parent::__construct();
	}

	public function getData(): array
	{
		$data = parent::getData();
		$data[self::SETUP_TEMPLATE_DATA] = $this->setupTemplateEvent?->toArray();

		return $data;
	}
}