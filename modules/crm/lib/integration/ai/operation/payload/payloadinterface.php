<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload;

use Bitrix\Main\Result;

interface PayloadInterface
{
	public function getPayloadCode(): string;
	public function setAdditionalData(array $data): self;
	public function setMarkers(array $markers): self;
	public function setEncodedMarkers(array $encodedMarkers): self;
	public function getResult(): Result;
}
