<?php


namespace Bitrix\Crm\Service\Display\Field;


use Bitrix\Crm\Service\Display\Options;
use Bitrix\UI\Public\System\Label\Label;
use Bitrix\UI\Public\System\Label\Style;

class StatusField extends StringField
{
	public const TYPE = 'status';

	public function getType(): string
	{
		return self::TYPE;
	}

	protected function render(Options $displayOptions, $entityId, $value): string
	{
		throw new Exception('Multiple values are not supported');
	}

	protected function renderSingleValue($fieldValue, int $itemId, Options $displayOptions): string
	{
		$this->setWasRenderedAsHtml(true);

		$valueConfig = $this->getValueConfig($fieldValue);
		$text = $valueConfig['text'] ?? null;
		if (!$text)
		{
			return '';
		}

		return Label::create([
			'value' => $text,
			'style' => $this->getLabelStyleByPostfix((string)($valueConfig['cssPostfix'] ?? '')),
			'title' => $text,
		])->render();
	}

	protected function getLabelStyleByPostfix(string $postfix): Style
	{
		return Style::TINTED_NO_ACCENT;
	}
}
