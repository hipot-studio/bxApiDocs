<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormHtmlPreparer;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;

class TaskPrepareHtmlBlocksCrmForms extends TaskStep
{
	private const ROOT_NODE_ID = 'crm-form-root';

	public function execute(): bool
	{
		parent::execute();

		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($this->generation);
		if (empty($htmlBlocks))
		{
			return true;
		}

		$preparer = new CrmFormHtmlPreparer();
		$changed = false;
		foreach ($htmlBlocks as $index => $item)
		{
			$html = (string)($item['html'] ?? '');
			if ($html === '' || !str_contains($html, '#CRM_FORM#'))
			{
				continue;
			}

			$context = CrmFormColorNormalizer::buildContextFromHtmlBlock($item, $html);
			$preparedHtml = $preparer->prepare(
				$html,
				self::ROOT_NODE_ID,
				$preparer->buildFallbackEmbedHtml($context),
				$context,
			);
			if ($preparedHtml === $html)
			{
				continue;
			}

			$htmlBlocks[$index]['html'] = $preparedHtml;
			$changed = true;
		}

		if ($changed)
		{
			CreateAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
			$this->changed = true;
		}

		return true;
	}
}
