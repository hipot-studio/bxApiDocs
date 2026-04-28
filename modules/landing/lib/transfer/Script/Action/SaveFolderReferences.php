<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;

class SaveFolderReferences extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$data = $this->context->getData();
		$siteId = $this->context->getSiteId() ?? 0;
		$folderId = (int)($this->context->getAdditionalOptions()->get(AdditionalOptionPart::FolderId) ?? 0);

		if (empty($data) || $siteId <= 0)
		{
			return;
		}

		$refs = $ratio->get(RatioPart::FolderReferences) ?? [];
		$convertFolderOldFormat = false;
		if ($data['FOLDER'] === 'Y')
		{
			$convertFolderOldFormat = true;
			$data['FOLDER'] = 'N';
			$res = Site::addFolder($siteId, [
				'TITLE' => $data['TITLE'],
				'CODE' => $data['CODE'],
			]);
			if ($res->isSuccess())
			{
				$oldId = $this->context->getRunData()->get(RunDataPart::OldId) ?? 0;
				$data['FOLDER_ID'] = $res->getId();
				$refs[$oldId] = $data['FOLDER_ID'];
			}
		}
		elseif ($folderId > 0)
		{
			$data['FOLDER_ID'] = $folderId;
		}

		$ratio->set(RatioPart::FolderReferences, $refs);
		$this->context->getRunData()->set(RunDataPart::NeedConvertFoldersOldFormat, $convertFolderOldFormat);
		$this->context->setData($data);
	}
}
