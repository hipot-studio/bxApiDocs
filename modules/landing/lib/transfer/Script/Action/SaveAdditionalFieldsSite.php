<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Hook;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class SaveAdditionalFieldsSite extends Blank
{
	use ContexterTrait;

	/**
	 * @inheritdoc
	 */
	public function action(): void
	{
		$data = $this->context->getData();
		$ratio = $this->context->getRatio();

		if (!$ratio->get(RatioPart::AdditionalFieldsSite))
		{
			$additionalFields = $data['ADDITIONAL_FIELDS'] ?? [];
			$additionalFields = $this->filterAdditionalFields($additionalFields);
			$ratio->set(RatioPart::AdditionalFieldsSite, $additionalFields);
		}

		if (!$ratio->get(RatioPart::AdditionalFieldsSiteBefore))
		{
			$siteId = $this->context->getSiteId();
			if ($siteId)
			{
				$ratio->set(
					RatioPart::AdditionalFieldsSiteBefore,
					$this->filterAdditionalFields(self::getAdditionalFieldsCurrent($siteId))
				);
			}
		}
	}

	private static function getAdditionalFieldsCurrent(int $siteId): array
	{
		$additionalFields = [];
		$hooks = Hook::getData($siteId, Hook::ENTITY_TYPE_SITE);
		foreach ($hooks as $hook => $fields)
		{
			foreach ($fields as $code => $field)
			{
				$additionalFields[$hook . '_' . $code] = $field;
			}
		}

		return $additionalFields;
	}

}
