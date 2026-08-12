<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormHtmlPreparer;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;

class TaskPrepareChangeAiSiteBlocksCrmForms extends TaskStep
{
	private const ROOT_NODE_ID = 'change-ai-site-crm-form-root';

	public function execute(): bool
	{
		parent::execute();

		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($this->generation);
		if ($htmlBlocks === [])
		{
			return true;
		}

		$preparer = new CrmFormHtmlPreparer();
		$changed = false;
		foreach ($htmlBlocks as &$item)
		{
			$html = (string)($item['generatedHtml'] ?? '');
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

			$item['generatedHtml'] = $preparedHtml;
			$changed = true;
		}
		unset($item);

		if ($changed)
		{
			ChangeAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
			$this->changed = true;
		}

		return true;
	}
}
