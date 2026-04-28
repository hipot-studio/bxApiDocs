<?php

namespace Bitrix\Crm\Model\FieldRepository;

use Bitrix\Main\Localization\Loc;

enum FieldCaptionGender
{
	case Masculine;
	case Feminine;
	case Neutral;

	public function getPostfix(): string
	{
		return match ($this) {
			self::Feminine => '_FEMININE',
			self::Neutral => '_NEUTRAL',
			default => '',
		};
	}

	public function appendPostfix(string $messageCode): string
	{
		return $messageCode . $this->getPostfix();
	}

	public function getMessage(string $code, array $replace = []): string
	{
		return Loc::getMessage($code . $this->getPostfix(), $replace) ?? '';
	}
}
