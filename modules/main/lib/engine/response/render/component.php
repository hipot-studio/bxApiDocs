<?php

namespace Bitrix\Main\Engine\Response\Render;

/**
 * Response with component on site template.
 *
 * @see \Bitrix\Main\Engine\Response\Component for AJAX responses.
 */
final class Component extends Base
{
	public function __construct(
		private string $name,
		private string $template,
		private array $params = [],
		bool $withSiteTemplate = true,
		private ?string $siteTemplateId = null,
	)
	{
		parent::__construct($withSiteTemplate);
	}

	protected function renderContent(): void
	{
		$component = new \CBitrixComponent();
		if ($component->initComponent($this->name))
		{
			if (isset($this->siteTemplateId))
			{
				$component->setSiteTemplateId($this->siteTemplateId);
			}

			$component->includeComponent($this->template, $this->params, null);
		}
	}
}
