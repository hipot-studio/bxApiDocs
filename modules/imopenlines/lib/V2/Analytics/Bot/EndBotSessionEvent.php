<?php

declare(strict_types=1);

namespace Bitrix\ImOpenLines\V2\Analytics\Bot;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Engine\Response\Converter;

final class EndBotSessionEvent
{
	private ?EndBotSessionEventContext $context = null;
	private const TOOL = 'imopenlines';
	private const CATEGORY = 'channel';
	private const EVENT = 'transfer';

	public function setContext(?EndBotSessionEventContext $context): self
	{
		$this->context = $context;

		return $this;
	}

	public function send(): void
	{
		if (!$this->context?->isValid())
		{
			return;
		}

		$converter = new Converter(Converter::TO_CAMEL | Converter::LC_FIRST);
		$botCode = $converter->process($this->context->getBotCode());

		(new AnalyticsEvent(
			event: self::EVENT,
			tool: self::TOOL,
			category: self::CATEGORY,
		))
			->setType($this->context->getMode())
			->setP1("botCode_{$botCode}")
			->send()
		;
	}
}
