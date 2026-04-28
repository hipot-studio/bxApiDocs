<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Main\Loader;
use Bitrix\Landing;
use Bitrix\Landing\Site;
use Bitrix\Landing\History;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\BlockTrait;
use Bitrix\Crm;

class ReplaceBlocks extends Blank
{
	use BlockTrait;

	public function action(): void
	{
		// todo: run action only for index? Or how preserve multipage import
		// todo: but how set new template for page if old page have sidebar f.e.?
		$data = $this->context->getData();
		$replaceLid = (int)$this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId);
		if (empty($data) || empty($data['BLOCKS']) || $replaceLid <= 0)
		{
			return;
		}

		$landing = Landing\Landing::createInstance($replaceLid);
		$blocksBefore = [];
		$blocksAfter = [];

		History::deactivate();
		foreach ($landing->getBlocks() as $block)
		{
			$blockId = $block->getId();
			$block->setAccess(Landing\Block::ACCESS_X);
			if ($landing->markDeletedBlock($block->getId(), true))
			{
				$blocksBefore[] = $blockId;
			}
		}

		$structure = $this->context->getStructure();
		if (!isset($structure))
		{
			return;
		}

		$this->importBlocks($landing);

		$landings = $this->context->getRatio()->get(RatioPart::Landings) ?? [];
		$landings[$replaceLid] = $replaceLid;
		$this->context->getRatio()->set(RatioPart::Landings, $landings);

		// find form block and replace form ID if need
		$meta = $landing->getMeta();
		$isCrmFormSite = null;
		if ($meta['SITE_SPECIAL'] === 'Y')
		{
			$isCrmFormSite =
				Site\Type::getSiteSpecialType($meta['SITE_CODE']) === Site\Type::PSEUDO_SCOPE_CODE_FORMS;
		}
		if ($isCrmFormSite && Loader::includeModule('crm'))
		{
			// find form
			$res = Crm\WebForm\Internals\LandingTable::getList([
				'select' => [
					'FORM_ID',
				],
				'filter' => [
					'=LANDING_ID' => $replaceLid,
				],
			]);
			$row = $res->fetch();
			$formId = (int)($row ? $row['FORM_ID'] : null);
			if ($formId > 0)
			{
				foreach ($landing->getBlocks() as $block)
				{
					$manifest = $block->getManifest();
					if (($manifest['block']['subtype'] ?? null) === 'form')
					{
						Landing\Subtype\Form::setFormIdToBlock($block->getId(), $formId);
						if ($block->getAccess() > Landing\Block::ACCESS_W)
						{
							Landing\Internals\BlockTable::update($block->getId(), [
								'ACCESS' => Landing\Block::ACCESS_W,
							]);
						}
					}
				}
			}
		}

		if (Landing\Manager::isAutoPublicationEnabled())
		{
			$landing->publication();
		}

		$additionalFieldsBefore = $this->context->getRunData()->get(RunDataPart::AdditionalFieldsBefore);
		if (isset($additionalFieldsBefore))
		{
			History::activate();
			$history = new History($replaceLid, History::ENTITY_TYPE_LANDING);
			$history->push('REPLACE_LANDING', [
				'lid' => $replaceLid,
				'template' => $this->context->getCode() ?? '',
				'blocksBefore' => $blocksBefore,
				'blocksAfter' => $blocksAfter,
				'additionalFieldsBefore' => $additionalFieldsBefore,
				'additionalFieldsAfter' => $data['ADDITIONAL_FIELDS'],
			]);
		}
	}
}
