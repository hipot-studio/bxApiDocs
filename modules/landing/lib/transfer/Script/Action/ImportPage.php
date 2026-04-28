<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Folder;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\BlockTrait;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;
use Bitrix\Landing\Transfer\TransferException;

class ImportPage extends Blank
{
	use ContexterTrait;
	use BlockTrait;

	public function action(): void
	{
		$data = $this->context->getData();
		if (empty($data))
		{
			return;
		}

		$res = Landing::add($data);
		if (!$res->isSuccess())
		{
			throw new TransferException($res->getErrorMessages());
		}
		$newLid = (int)$res->getId();
		$this->context->getRunData()->set(RunDataPart::NewId, $newLid);

		$isNeedConvertFoldersOldFormat = $this->context->getRunData()->get(RunDataPart::NeedConvertFoldersOldFormat);
		if ($isNeedConvertFoldersOldFormat && ($data['FOLDER_ID'] ?? 0))
		{
			Folder::update($data['FOLDER_ID'], ['INDEX_ID' => $newLid]);
		}

		$this->saveAdditionalFilesToLanding($newLid);

		// store old id and other references
		$ratio = $this->context->getRatio();
		$oldLid = $this->context->getRunData()->get(RunDataPart::OldId);
		if (isset($oldLid))
		{
			$landings = $ratio->get(RatioPart::Landings) ?? [];
			$landings[$oldLid] = $newLid;
			$ratio->set(RatioPart::Landings, $landings);
		}

		$templateLinking = $ratio->get(RatioPart::TemplateLinking) ?? [];
		$templateLinking[$newLid] = [
			'TPL_ID' => (int)$data['TPL_ID'],
			'TEMPLATE_REF' => (array)($data['TEMPLATE_REF'] ?? []),
		];
		$this->context->getRatio()->set(RatioPart::TemplateLinking, $templateLinking);

		$landing = Landing::createInstance($newLid);
		$this->importBlocks($landing);
	}

}
