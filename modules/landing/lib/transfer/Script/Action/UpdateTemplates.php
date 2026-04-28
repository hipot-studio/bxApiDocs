<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Site;
use Bitrix\Landing\Template;
use Bitrix\Landing\TemplateRef;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateTemplates extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$templates = $ratio->get(RatioPart::Templates);
		$templateLinking = $ratio->get(RatioPart::TemplateLinking);
		$landings = $ratio->get(RatioPart::Landings) ?? [];

		// gets actual layouts
		$templatesNew = [];
		$templatesRefs = [];
		$res = Template::getList([
			'select' => [
				'ID', 'XML_ID',
			],
		]);
		while ($row = $res->fetch())
		{
			$templatesNew[$row['XML_ID']] = $row['ID'];
		}
		foreach ($templates as $oldId => $oldXmlId)
		{
			if (is_string($oldXmlId) && isset($templatesNew[$oldXmlId]))
			{
				$templatesRefs[$oldId] = $templatesNew[$oldXmlId];
			}
		}

		// set layouts to site and landings
		foreach ($templateLinking as $entityId => $templateItem)
		{
			$tplId = $templateItem['TPL_ID'];
			$tplRefs = [];
			if (isset($templatesRefs[$tplId]))
			{
				$tplId = $templatesRefs[$tplId];
				foreach ($templateItem['TEMPLATE_REF'] as $areaId => $landingId)
				{
					$landingId = (int)$landingId;
					if (($landingId) && isset($landings[$landingId]))
					{
						$tplRefs[$areaId] = $landings[$landingId];
					}
				}
				if ($entityId < 0)
				{
					$entityId = -1 * $entityId;
					Site::update($entityId, [
						'TPL_ID' => $tplId,
					]);
					TemplateRef::setForSite($entityId, $tplRefs);
				}
				else
				{
					Landing::update($entityId, [
						'TPL_ID' => $tplId,
					]);
					TemplateRef::setForLanding($entityId, $tplRefs);
				}
			}
		}
	}
}
