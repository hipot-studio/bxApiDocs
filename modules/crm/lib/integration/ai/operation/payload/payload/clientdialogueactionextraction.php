<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;

final class ClientDialogueActionExtraction extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'client_dialogue_action_extraction';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($this->calcMarkers(), $markers);

		return $this;
	}

	public function calcMarkers(): array
	{
		$activity = $this->getActivity();

		return [
			'employee_name' => $this->getUserName((int)($activity['RESPONSIBLE_ID'] ?? 0)),
			'dialogue_start_datetime' => $activity['START_TIME'] ?? null,
		];
	}
}
