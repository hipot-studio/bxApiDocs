<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Service\OnlyOffice\Handlers\CustomOnlyOfficeServerHandler;
use Bitrix\Disk\Internal\Service\R7\Handlers\CustomR7ServerHandler;
use Bitrix\Main\NotImplementedException;

class CustomServerConfigsMapper
{
	protected ?array $normalCodesToCustomCodes = null;
	protected ?array $customCodesToNormalCodes = null;

	/**
	 * @return array
	 * @throws NotImplementedException
	 */
	public function getForNormalCodes(): array
	{
		if (!is_array($this->normalCodesToCustomCodes))
		{
			$this->normalCodesToCustomCodes = [
				OnlyOfficeHandler::getCode() => [
					CustomServerTypes::R7->value => CustomR7ServerHandler::getCode(),
					CustomServerTypes::OnlyOffice->value => CustomOnlyOfficeServerHandler::getCode(),
				],
			];
		}

		return $this->normalCodesToCustomCodes;
	}

	/**
	 * @return array
	 * @throws NotImplementedException
	 */
	public function getForCustomCodes(): array
	{
		if (!is_array($this->customCodesToNormalCodes))
		{
			$forNormalCodes = $this->getForNormalCodes();

			foreach ($forNormalCodes as $normalCode => $forTypes)
			{
				foreach ($forTypes as $typeString => $customCode)
				{
					$this->customCodesToNormalCodes[$customCode] = [
						'normalCode' => $normalCode,
						'customConfigType' => CustomServerTypes::tryFrom($typeString),
					];
				}
			}
		}

		return $this->customCodesToNormalCodes;
	}
}
