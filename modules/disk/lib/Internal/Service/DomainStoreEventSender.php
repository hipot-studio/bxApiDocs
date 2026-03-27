<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Service\MicroService\BaseSender;

class DomainStoreEventSender extends BaseSender
{
	protected function getServiceUrl(): string
	{
		return Application::getInstance()->getLicense()->getDomainStoreLicense() . '/b24/receiver.php';
	}
}
