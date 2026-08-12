<?php

namespace Bitrix\Crm\Component\EntityList;

use Bitrix\Crm\Badge\ValueItemOptions;

final class BadgeBuilder
{
	private static bool $isHintInitiated = false;

	public static function render(array $badges): string
	{
		$badge = current($badges);
		if (!$badge)
		{
			return '';
		}

		$titleText = htmlspecialcharsbx($badge['fieldName']);
		$hint = htmlspecialcharsbx($badge['hint'] ?? '');
		$style = $badge['style'] ?? ValueItemOptions::STYLE_TINTED_NO_ACCENT;
		$text = htmlspecialcharsbx($badge['textValue']);

		$hintAttr = $hint !== '' ? " data-badgehint=\"{$hint}\"" : '';

		$html = <<<HTML
			<div class="crm-kanban-item-badges">
				<div class="crm-kanban-item-badges-item">
					<div class="crm-kanban-item-badges-item-title">
						<div class="crm-kanban-item-badges-item-title-text">{$titleText}</div>
					</div>
					<div class="crm-kanban-item-badges-item-value"{$hintAttr}>
						<div class="ui-system-label --size-md --style-{$style}" title="{$text}">
							<div class="ui-system-label__inner">
								<div class="ui-system-label__value">{$text}</div>
							</div>
						</div>
					</div>
				</div>
			</div>
HTML;

		$js = '';
		if ($hint !== '' && !self::$isHintInitiated)
		{
			self::$isHintInitiated = true;

			$js = <<<JS
				<script type="text/javascript">
					BX.ready(() => {
						document.querySelectorAll('.crm-kanban-item-badges [data-badgehint]').forEach((item) => {
							const badge = new BX.Crm.Badge(item);
							badge.init();
						});
					});
				</script>
JS;
		}

		return $html . PHP_EOL . $js;
	}
}
