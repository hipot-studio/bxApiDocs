<?php

namespace Bitrix\Biconnector\Internal\Grid;

use Bitrix\UI\Buttons\BaseButton;
use Bitrix\UI\Typography\Headline;
use Bitrix\UI\Typography\Text;

final class GridStubRenderer
{
	public static function render(
		string $iconPath,
		string $title,
		?string $description = null,
		?BaseButton $button = null,
	): string
	{
		$iconPath = htmlspecialcharsbx($iconPath);

		$titleHtml = Headline::render(
			align: 'center',
			className: 'biconnector-grid-stub__title',
			content: $title,
		);

		$descriptionHtml = ($description !== null && $description !== '')
			? Text::render(
			size: 'lg',
			tag: 'div',
			align: 'center',
			className: 'biconnector-grid-stub__description',
			content: $description,
			)
			: ''
		;

		$buttonHtml = $button !== null
			? '<div class="biconnector-grid-stub__button">' . $button->render(false) . '</div>'
			: ''
		;

		return <<<HTML
			<div class="biconnector-grid-stub">
				<img
					class="biconnector-grid-stub__image"
					src="{$iconPath}"
					alt=""
				>
				<div class="biconnector-grid-stub__content">
					{$titleHtml}
					{$descriptionHtml}
					{$buttonHtml}
				</div>
			</div>
		HTML;
	}
}