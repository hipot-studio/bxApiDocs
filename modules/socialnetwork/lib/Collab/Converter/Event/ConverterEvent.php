<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Converter\Event;

use Bitrix\Main\Event;
use Bitrix\Socialnetwork\Item\Workgroup;

class ConverterEvent extends Event
{
	public function __construct(Workgroup $entityBefore, Workgroup $entityAfter)
	{
		$parameters = [
			'entityBefore' => $entityBefore,
			'entityAfter' => $entityAfter,
		];

		parent::__construct('socialnetwork', 'OnWorkgroupConvert', $parameters);
	}

	public function getEntityBefore(): Workgroup
	{
		return $this->parameters['entityBefore'];
	}

	public function getEntityAfter(): Workgroup
	{
		return $this->parameters['entityAfter'];
	}
}