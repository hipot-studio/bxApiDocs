<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\NewMail;

use Bitrix\Mail\Public\Features\Bitrix24MailFeature;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Loader;
use Bitrix\MailService\Public\Service\MailhostExchange;
use Closure;
use Throwable;

/**
 * Admission requires CIS and the flag; showing the New mail also requires the exchange of mailservice.
 */
final class NewMailSwitch
{
	private readonly Closure $flagReader;

	/** Answers the public point of mailservice, or null when there is no such point: the exchange is then off. */
	private readonly Closure $exchangeReader;
	private readonly Closure $cisReader;

	public function __construct(
		?Closure $flagReader = null,
		?Closure $exchangeReader = null,
		?Closure $cisReader = null,
	)
	{
		// The flag decides for the whole portal, so it is asked without the user of the hit that happens to ask.
		$this->flagReader = $flagReader
			?? static fn(): bool => Feature::isEnabled(Bitrix24MailFeature::class, new Context());
		$this->exchangeReader = $exchangeReader ?? self::askExchangePoint(...);
		$this->cisReader = $cisReader ?? static fn(): bool => Application::getInstance()->getLicense()->isCis();
	}

	public function isAccepting(): bool
	{
		try
		{
			if (($this->cisReader)() !== true)
			{
				return false;
			}
		}
		catch (Throwable)
		{
			return false;
		}

		return ($this->flagReader)() === true;
	}

	public function isExchangeEnabled(): bool
	{
		try
		{
			return ($this->exchangeReader)() === true;
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public function isShown(): bool
	{
		return $this->isAccepting() && $this->isExchangeEnabled();
	}

	private static function askExchangePoint(): ?bool
	{
		if (!Loader::includeModule('mailservice') || !class_exists(MailhostExchange::class))
		{
			return null;
		}

		return (new MailhostExchange())->isEnabled();
	}
}
