<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Entity\FieldDataProvider;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\Service\Context;

final class ExtractFormFields extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'extract_form_fields';
	}
	
	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());
		
		return $this;
	}

	public function calcMarkers(): array
	{
		return [
			'fields' => $this->getFields(),
		];
	}

	private function getFields(): array
	{
		$fields = [
			// unallocated data
			'comment' => 'list[string]',
		];
		
		// sent to AI all available fields, regardless of user
		$suitableFields =  (new FieldDataProvider($this->identifier->getEntityTypeId(), Context::SCOPE_AI))
			->getFieldData()
		;

		foreach ($suitableFields as $fieldDescription)
		{
			if ($fieldDescription['MULTIPLE'])
			{
				$type = 'list[' . $fieldDescription['TYPE'] . ']';
			}
			else
			{
				$type = $fieldDescription['TYPE'];
			}
			
			$fields[$fieldDescription['NAME']] = "{$type} or null";
		}
		
		return $fields;
	}
}
