<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\TariffLimit\Event;

use Bitrix\Im\V2\Common\Event\BaseEvent;
use Bitrix\Im\V2\TariffLimit\TariffRestrictionCollection;
use Bitrix\Main\EventResult;

class CollectRestrictionsEvent extends BaseEvent
{
	public const TYPE = 'OnCollectTariffRestrictions';

	public function __construct(TariffRestrictionCollection $restrictions = new TariffRestrictionCollection())
	{
		parent::__construct(self::TYPE, ['restrictions' => $restrictions]);
	}

	public function getRestrictions(): TariffRestrictionCollection
	{
		return $this->parameters['restrictions'];
	}

	public function getNewRestrictions(): TariffRestrictionCollection
	{
		$merged = $this->getRestrictions();

		foreach ($this->getResults() as $result)
		{
			if ($result->getType() !== EventResult::SUCCESS)
			{
				continue;
			}

			$partial = $result->getParameters()['restrictions'] ?? null;
			if (!$partial instanceof TariffRestrictionCollection)
			{
				continue;
			}

			$merged = $merged->merge($partial);
		}

		return $merged;
	}
}
