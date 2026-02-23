<?php
declare(strict_types=1);

namespace Bitrix\AI\Cloud;

use Bitrix\AI\Cloud\Dto\RegistrationDto;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

final class SendClientModuleVersion extends BaseSender
{
	/**
	 * @param RegistrationDto $registrationDto
	 * @return Result
	 * @throws ArgumentException
	 */
	public function send(RegistrationDto $registrationDto): Result
	{
		$data = [
			'clientId' => $registrationDto->clientId,
			'clientData' => [
				'moduleVersion' => ModuleManager::getVersion('ai')
			],
		];

		/** @see \Bitrix\AiProxy\Controller\Registration::updateClientAction() */
		return $this->performRequest('aiproxy.Registration.updateClient', $data);
	}
}
