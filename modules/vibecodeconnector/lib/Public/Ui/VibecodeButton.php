<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Ui;

use Bitrix\Main\UI\Extension;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Icon;
use Bitrix\UI\Buttons\Size;
use Bitrix\Vibecodeconnector\Internal\Entity\Vibecode\VibecodeBrand;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\VibecodeUrlProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\VibecodeBrandResolver;

final class VibecodeButton
{
	private const CSS_CLASS = 'vibecodeconnector-vibecode-button';
	private const BRAND_ATTRIBUTE = 'vibecode-brand';

	public static function create(): Button
	{
		$brand = (new VibecodeBrandResolver())->resolve();

		$button = new Button([
			'air' => true,
			'text' => $brand->getDisplayName(),
			'link' => (new VibecodeUrlProvider())->getDashboardUrl(),
			'target' => '_blank',
			'icon' => Icon::VIBECODE,
			'size' => Size::SMALL,
			'classList' => [self::CSS_CLASS, '--ui-context-edge-dark'],
		]);

		$button->addAttribute('rel', 'noopener noreferrer');
		$button->addDataAttribute(self::BRAND_ATTRIBUTE, $brand->value);

		$width = $brand === VibecodeBrand::Alaio ? '139px' : '173px';
		$backgroundColor = $brand === VibecodeBrand::Alaio ? '#160a35' : '#090d30';
		$button->setStyles([
			'width' => "var(--vibecode-button-width, {$width})",
			'min-width' => "var(--vibecode-button-width, {$width})",
			'height' => 'var(--ui-size-2xl, 28px)',
			'background-color' => $backgroundColor,
		]);

		Extension::load('vibecodeconnector.vibecode-button');

		return $button;
	}
}
