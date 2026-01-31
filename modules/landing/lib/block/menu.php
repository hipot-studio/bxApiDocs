<?php
namespace Bitrix\Landing\Block;


use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Web\DOM\Document;
use Bitrix\Landing\Block;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Sanitizer;
use Bitrix\Landing\Assets;

/**
 * Class for work with rendered menu.
 * It is not a node type, block subtype or field type, see for the 'menu' key in the array
 * at the same level as 'block'. F.e. block 0.menu_25
 */
class Menu
{
	private Block $block;
	private Document $doc;
	private array $menuManifest;

	public function __construct(Block $block)
	{
		$this->block = $block;
		$this->doc = $block->getDom();

		$manifest = $block->getManifest();
		if (!isset($manifest['menu']))
		{
			throw new ArgumentTypeException('Invalid block: has no menu');
		}
		$this->menuManifest = $manifest['menu'];
	}

	public function updateMenu(array $data, bool $appendMenu = false): void
	{
		if ($appendMenu)
		{
			$export = $this->block->export();
		}
		foreach ($this->menuManifest as $selector => $node)
		{
			if (isset($data[$selector]) && is_array($data[$selector]))
			{
				if (isset($data[$selector][0][0]))
				{
					$data[$selector] = array_shift($data[$selector]);
				}
				if ($appendMenu && isset($export['menu'][$selector]))
				{
					$data[$selector] = array_merge(
						$export['menu'][$selector],
						$data[$selector]
					);
				}

				$resultList = $this->doc->querySelectorAll($selector);
				if (empty($resultList))
				{
					continue;
				}
				$resultNode = array_shift($resultList);
				$parentNode = $resultNode->getParentNode();
				$parentNode?->setInnerHtml(
					$this->getMenuHtml(
						$data[$selector],
						$node
					)
				);
			}
		}
	}

	/**
	 * Returns menu html with child submenu.
	 * @param array $data Data array.
	 * @param array $manifestNode Manifest node for current selector.
	 * @param string $level Level (root or children).
	 * @return string
	 */
	private function getMenuHtml(array $data, array $manifestNode, string $level = 'root'): string
	{
		if (!isset($manifestNode[$level]))
		{
			return '';
		}

		$htmlContent = '';
		$rootSelector = $manifestNode[$level];

		if (
			isset($rootSelector['ulClassName'], $rootSelector['liClassName'], $rootSelector['aClassName'])
			&& is_string($rootSelector['ulClassName'])
			&& is_string($rootSelector['liClassName'])
			&& is_string($rootSelector['aClassName'])
		)
		{
			$sanitizer = new Sanitizer();

			foreach ($data as $menuItem)
			{
				if (
					isset($menuItem['text'], $menuItem['href'])
					&& is_string($menuItem['text'])
					&& is_string($menuItem['href'])
				)
				{
					// todo: check sanitize 'page:#landing0'
					$href = $sanitizer->sanitizeText(trim($menuItem['href']));
					if ($href === 'page:#landing0')
					{
						$res = Landing::addByTemplate(
							$this->block->getSiteId(),
							Assets\PreProcessing\Theme::getNewPageTemplate($this->block->getSiteId()),
							[
								'TITLE' => $menuItem['text'],
							]
						);
						if ($res->isSuccess())
						{
							$href = '#landing' . $res->getId();
						}
					}


					$target = $sanitizer->sanitizeHrefTarget((string)$menuItem['target']);
					$text = $sanitizer->sanitizeText($menuItem['text']);

					$htmlContent .= '<li class="' . \htmlspecialcharsbx($rootSelector['liClassName']) . '">';
					$htmlContent .=
						'<a href="' . \htmlspecialcharsbx($href) . '" target="' . $target . '" 
						class="' . \htmlspecialcharsbx($rootSelector['aClassName']) . '">'
					;
					$htmlContent .= \htmlspecialcharsbx($text);
					$htmlContent .= '</a>';
					if (isset($menuItem['children']))
					{
						$htmlContent .= $this->getMenuHtml(
							$menuItem['children'],
							$manifestNode,
							'children'
						);
					}
					$htmlContent .= '</li>';
				}
			}

			if ($htmlContent)
			{
				$htmlContent = '<ul class="' . \htmlspecialcharsbx($rootSelector['ulClassName']) . '">' .
					$htmlContent .
					'</ul>';
			}
			else if ($level === 'root')
			{
				$htmlContent = '<ul class="' . \htmlspecialcharsbx($rootSelector['ulClassName']) . '"></ul>';
			}
		}

		return $htmlContent;
	}
}