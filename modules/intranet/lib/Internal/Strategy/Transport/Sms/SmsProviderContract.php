<?php

namespace Bitrix\Intranet\Internal\Strategy\Transport\Sms;


use Bitrix\Intranet\Entity\Type\Phone;

interface SmsProviderContract
{
	public function send(Phone $phone, string $message): void;
}