<?php

namespace Bitrix\Bizproc\Public\Integration\AI\Service;

use Bitrix\Bizproc\Internal\Integration\AI\Event\ChatHistoryAiEventHandler;
use Bitrix\Bizproc\Public\Event\ParameterBuilder\AI\Context\ChatHistoryEventParametersBuilder;
use Bitrix\Main\Event;

class ChatHistoryService
{
	private bool $usePseudonymizer = false;

	/**
	 * @param array $workflowTriggerData
	 *
	 * @return array{messages: list<array{content: string, role: string}>} Messages in ascending order from old to new
	 */
	public function getByWorkflowTriggerData(array $workflowTriggerData, int $salt = 0): array
	{
		$eventParameterBuilder = new ChatHistoryEventParametersBuilder($workflowTriggerData);
		if (!$eventParameterBuilder->isSupported())
		{
			return [];
		}

		$eventParameters = [ChatHistoryAiEventHandler::PARAMETER_PARAMS => $eventParameterBuilder->getParams()];

		$fakeEvent = new Event('bizproc', 'fake', $eventParameters);
		$fakeEvent->setParameter('salt', $salt);
		$fakeEvent->setParameter('usePseudonymizer', $this->usePseudonymizer);

		$history = ChatHistoryAiEventHandler::makeChatHistoryResponse($fakeEvent);
		$history['messages'] = array_reverse((array)($history['messages'] ?? []));

		return $history;
	}

	public function setUsePseudonymizer(bool $usePseudonymizer): self
	{
		$this->usePseudonymizer = $usePseudonymizer;
		return $this;
	}
}
