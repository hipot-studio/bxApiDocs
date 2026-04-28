<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\OnlyOffice\Cloud\Registration;
use Bitrix\Disk\Document\OnlyOffice\Configuration;
use Bitrix\Disk\UserConfiguration;
use Bitrix\Main\Error;

class UnregisterCloudClientCommandHandler
{
	/**
	 * @param UnregisterCloudClientCommand $command
	 * @return array<int, Error>
	 */
	public function __invoke(UnregisterCloudClientCommand $command): array
	{
		$configuration = new Configuration();
		$cloudRegistrationData = $configuration->getCloudRegistrationData();

		if (!is_array($cloudRegistrationData))
		{
			return [];
		}

		$serviceUrl = $cloudRegistrationData['serverHost'];
		$cloudRegistration = new Registration($serviceUrl);
		$unregisterPortalResult = $cloudRegistration->unregisterPortal();

		if ($unregisterPortalResult->isSuccess())
		{
			$configuration->resetCloudRegistration();
			UserConfiguration::resetDocumentServiceForAllUsers();
			DocumentSessionTable::deleteByService(DocumentService::OnlyOffice);

			return [];
		}

		return $unregisterPortalResult->getErrors();
	}
}
