<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\AI;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Client;

abstract class Base extends LogMessage
{
	public function getContentBlocks(): ?array
	{
		$result = [];

		$clientBlock = $this->buildClientBlock($this->isCallAssociated() ? Client::BLOCK_WITH_FORMATTED_VALUE : 0);
		if (isset($clientBlock))
		{
			$result['client'] = $clientBlock;
		}

		return $result;
	}

	final protected function isCallAssociated(): ?string
	{
		return $this->getAssociatedEntityModel()?->get('PROVIDER_ID') === Call::getId();
	}

	final protected function isOpenLineAssociated(): ?string
	{
		return $this->getAssociatedEntityModel()?->get('PROVIDER_ID') === OpenLine::getId();
	}
}
