<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action\Closet;

use Bitrix\Landing\File;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Transfer\Requisite\Context;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;

/** @property Context $context */
trait ContexterTrait
{
	protected function filterAdditionalFields(array $additionalFields): array
	{
		$result = [];
		foreach (Hook::HOOKS_CODES_DESIGN as $hookCode)
		{
			$result[$hookCode] = $additionalFields[$hookCode] ?? '';
		}

		return $result;
	}

	protected function saveAdditionalFilesToLanding(int $landingId): void
	{
		$data = $this->context->getData();
		if (empty($data))
		{
			return;
		}

		foreach (Hook::HOOKS_CODES_FILES as $hookCode)
		{
			$fileId = (int)($data['ADDITIONAL_FIELDS'][$hookCode] ?? null);
			if ($fileId > 0)
			{
				File::addToLanding($landingId, $data['ADDITIONAL_FIELDS'][$hookCode]);
			}
		}
	}

	protected function isIndexPage(): bool
	{
		$data = $this->context->getData();
		$id = (int)(
			$data['ID']
			?? $this->context->getRunData()->get(RunDataPart::OldId)
			?? null
		);
		if ($id <= 0)
		{
			return false;
		}

		$ratio = $this->context->getRatio();
		$specialPages = $ratio->get(RatioPart::SpecialPages);
		$idIndex = (int)($specialPages['LANDING_ID_INDEX'] ?? null);

		return $idIndex === $id;
	}
}