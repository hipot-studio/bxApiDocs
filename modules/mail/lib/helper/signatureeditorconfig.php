<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

class SignatureEditorConfig
{
	/**
	 * Returns a common CHTMLEditor config shared by personal and corporate signature editors.
	 * The prefix parameterizes editor name/id: "{$prefix}-editor-name" / "{$prefix}-editor-id".
	 */
	public static function getHtmlEditorConfig(string $prefix, string $content): array
	{
		return [
			'name' => $prefix . '-editor-name',
			'id' => $prefix . '-editor-id',
			'siteId' => SITE_ID,
			'width' => '100%',
			'minBodyWidth' => '100%',
			'normalBodyWidth' => 680,
			'height' => 300,
			'minBodyHeight' => 300,
			'showTaskbars' => false,
			'showNodeNavi' => false,
			'autoResize' => true,
			'autoResizeOffset' => 40,
			'bbCode' => false,
			'saveOnBlur' => false,
			'bAllowPhp' => false,
			'limitPhpAccess' => false,
			'setFocusAfterShow' => false,
			'askBeforeUnloadPage' => true,
			'useFileDialogs' => false,
			'useLinkStat' => false,
			'controlsMap' => [
				['id' => 'Bold', 'compact' => true, 'sort' => 10],
				['id' => 'Italic', 'compact' => true, 'sort' => 20],
				['id' => 'Underline', 'compact' => true, 'sort' => 30],
				['id' => 'Strikeout', 'compact' => true, 'sort' => 40],
				['id' => 'RemoveFormat', 'compact' => true, 'sort' => 50],
				['id' => 'Color', 'compact' => true, 'sort' => 60],
				['id' => 'FontSelector', 'compact' => true, 'sort' => 70],
				['id' => 'FontSize', 'compact' => true, 'sort' => 80],
				['separator' => true, 'compact' => true, 'sort' => 90],
				['id' => 'OrderedList', 'compact' => true, 'sort' => 100],
				['id' => 'UnorderedList', 'compact' => true, 'sort' => 110],
				['id' => 'AlignList', 'compact' => true, 'sort' => 120],
				['separator' => true, 'compact' => true, 'sort' => 130],
				['id' => 'InsertLink', 'compact' => true, 'sort' => 140],
				['id' => 'InsertImage', 'compact' => true, 'sort' => 150],
				['id' => 'InsertTable', 'compact' => true, 'sort' => 170],
				['id' => 'Code', 'compact' => true, 'sort' => 180],
				['id' => 'Quote', 'compact' => true, 'sort' => 190],
				['separator' => true, 'compact' => true, 'sort' => 200],
				['id' => 'Fullscreen', 'compact' => true, 'sort' => 210],
				['id' => 'BbCode', 'compact' => true, 'sort' => 220],
				['id' => 'More', 'compact' => true, 'sort' => 400],
			],
			'content' => $content,
			'isCopilotEnabled' => false,
		];
	}
}
