<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\File;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\AppConfiguration;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;

class ImportSite extends Blank
{
	private int $newSiteId;

	public function action(): void
	{
		if (
			$this->isImportPageScript()
			|| $this->isReplaceScript()
		)
		{
			return;
		}

		$this->importSite();

		$ratio = $this->context->getRatio();
		if (isset($this->newSiteId))
		{
			$this->context->setSiteId($this->newSiteId);
		}
		$ratio->set(RatioPart::Blocks, []);
		$ratio->set(RatioPart::BlocksPending, []);
		$ratio->set(RatioPart::Landings, []);
		$ratio->set(
			RatioPart::Templates,
			$ratio->get(RatioPart::Templates) ?? []
		);

		$data = $this->context->getData() ?? [];
		if (isset($data['FOLDERS_NEW']))
		{
			$ratio->set(RatioPart::FoldersNew, $data['FOLDERS_NEW']);
		}

		if (isset($data['SYS_PAGES']))
		{
			$ratio->set(RatioPart::SysPages, $data['SYS_PAGES']);
		}
	}

	private function importSite(): void
	{
		$data = $this->context->getData();

		$structure = $this->context->getStructure();
		if (!$structure)
		{
			return;
		}

		$code = $this->getCode();

		$data['CODE'] = $code;
		$data['ACTIVE'] = 'Y';

		// files
		$files = [];
		foreach (Hook::HOOKS_CODES_FILES as $hookCode)
		{
			$fileId = (int)($data['ADDITIONAL_FIELDS'][$hookCode] ?? null);
			if ($fileId > 0)
			{
				$unpackFile = $structure->getUnpackFile($fileId);
				if ($unpackFile)
				{
					$data['ADDITIONAL_FIELDS'][$hookCode] = AppConfiguration::saveFile($unpackFile);
					$files[] = $data['ADDITIONAL_FIELDS'][$hookCode];
				}
				else
				{
					unset($data['ADDITIONAL_FIELDS'][$hookCode]);
				}
			}
		}

		$res = Site::add($data);
		if (!$res->isSuccess())
		{
			// todo: add exceptin
			return;
		}

		$this->context->setData($data);

		$this->newSiteId = (int)$res->getId();
		$this->context->getRunData()->set(RunDataPart::NewId, $this->newSiteId);
		if ($files && $res->isSuccess())
		{
			foreach ($files as $fileId)
			{
				File::addToSite($this->newSiteId, $fileId);
			}
		}
	}

	private function getCode(): string
	{
		$data = $this->context->getData();
		$code = $data['CODE'] ?? null;

		// if site path are exist, create random one
		if ($code)
		{
			$check = Site::getList([
				'select' => [
					'ID',
				],
				'filter' => [
					'=CODE' => $code,
				],
			]);
			if ($check->fetch())
			{
				$code = null;
			}
		}

		if (!$code)
		{
			$code = strtolower(\randString(10));
		}

		return $code;
	}
}
