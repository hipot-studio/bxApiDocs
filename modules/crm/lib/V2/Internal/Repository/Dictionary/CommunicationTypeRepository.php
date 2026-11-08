<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\Multifield\Type\Im;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationChannel;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationType;

/**
 * The value types a communication channel accepts, in the order the channel declares them.
 *
 * The set comes from the same TypeRepository that guards writing a value type, so there is no
 * second list of values to drift from it. Portal settings do not narrow the set: hiding the retired
 * messengers is a legacy behaviour and stays there.
 */
final class CommunicationTypeRepository
{
	private CommunicationTypeTitleResolver $titleResolver;

	public function __construct()
	{
		$this->titleResolver = new CommunicationTypeTitleResolver();
	}

	public function getById(CommunicationChannel $channel, string $id, string $languageId): ?CommunicationType
	{
		foreach ($this->getList($channel, $languageId) as $communicationType)
		{
			if ($communicationType->getId() === $id)
			{
				return $communicationType;
			}
		}

		return null;
	}

	/**
	 * @return list<CommunicationType>
	 */
	public function getList(CommunicationChannel $channel, string $languageId): array
	{
		$result = [];

		foreach (TypeRepository::getValueTypes($channel->value) as $valueType)
		{
			[$name, $nameShort] = $this->titleResolver->resolve($channel, $valueType, $languageId);

			$result[] = new CommunicationType(
				$valueType,
				$name,
				$nameShort,
				$this->isDeprecated($channel, $valueType),
			);
		}

		return $result;
	}

	private function isDeprecated(CommunicationChannel $channel, string $valueType): bool
	{
		return $channel === CommunicationChannel::Im
			&& in_array($valueType, Im::DEPRECATED_MESSENGERS, true)
		;
	}
}
