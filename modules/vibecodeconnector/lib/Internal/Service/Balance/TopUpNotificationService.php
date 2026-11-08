<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Context;
use Bitrix\Main\Localization\LanguageTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Entity\Vibecode\VibecodeBrand;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\TopUpNotificationSender;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\VibecodeBrandResolver;

final class TopUpNotificationService
{
	private array $numberSeparators = [];

	public function __construct(
		private readonly TopUpNotificationSender $sender = new TopUpNotificationSender(),
		private readonly PurchaseUrlProvider $purchaseUrlProvider = new PurchaseUrlProvider(),
		private readonly VibecodeBrandResolver $brandResolver = new VibecodeBrandResolver(),
	)
	{
	}

	/** @param list<int> $recipientIds */
	public function notifyAll(int $initiatorUserId, array $recipientIds, string $balanceSnapshot): bool
	{
		if ($recipientIds === [])
		{
			return false;
		}

		$message = fn(?string $languageId = null): ?string => $this->getMessage($balanceSnapshot, $languageId);
		$success = true;
		$tag = 'VIBECODE_TOP_UP_REQUEST_' . $initiatorUserId;
		foreach (array_values(array_unique($recipientIds)) as $recipientId)
		{
			if (!$this->sender->send($initiatorUserId, $recipientId, $tag, $message))
			{
				$success = false;
			}
		}

		return $success;
	}

	private function getMessage(string $balanceSnapshot, ?string $languageId): ?string
	{
		$balance = $this->formatBalance($balanceSnapshot, $languageId) . "\u{00A0}Ꝟ";

		$isAlaio = $this->brandResolver->resolve() === VibecodeBrand::Alaio;
		$template = Loc::getMessage(
			$isAlaio
				? 'VIBECODECONNECTOR_TOP_UP_REQUEST_NOTIFICATION_ALAIO'
				: 'VIBECODECONNECTOR_TOP_UP_REQUEST_NOTIFICATION',
			language: $languageId,
		);
		if ($template === null || trim($template) === '')
		{
			return null;
		}

		return str_replace(
			['#BALANCE#', '#PURCHASE_URL#'],
			[$balance, $this->purchaseUrlProvider->getUrl()],
			$template,
		);
	}

	private function formatBalance(string $raw, ?string $languageId): string
	{
		$effectiveLanguage = $languageId ?? Loc::getCurrentLang();
		if (!isset($this->numberSeparators[$effectiveLanguage]))
		{
			if ($effectiveLanguage === 'ru')
			{
				$this->numberSeparators[$effectiveLanguage] = [',', "\u{00A0}"];
			}
			else
			{
				$culture = null;
				if ($effectiveLanguage !== '' && $effectiveLanguage !== Loc::getCurrentLang())
				{
					$culture = LanguageTable::query()
						->setSelect([
							'DECIMAL_SEPARATOR' => 'CULTURE.NUMBER_DECIMAL_SEPARATOR',
							'THOUSANDS_SEPARATOR' => 'CULTURE.NUMBER_THOUSANDS_SEPARATOR',
						])
						->where('LID', $effectiveLanguage)
						->fetch()
					;
				}
				if (
					isset($culture['DECIMAL_SEPARATOR'], $culture['THOUSANDS_SEPARATOR'])
					&& $culture['DECIMAL_SEPARATOR'] !== ''
				)
				{
					$this->numberSeparators[$effectiveLanguage] = [
						$culture['DECIMAL_SEPARATOR'],
						$culture['THOUSANDS_SEPARATOR'],
					];
				}
				else
				{
					$culture = Context::getCurrent()->getCulture();
					$this->numberSeparators[$effectiveLanguage] = $culture->getCode() === 'ru'
						? [',', "\u{00A0}"]
						: [$culture->getNumberDecimalSeparator(), $culture->getNumberThousandsSeparator()]
					;
				}
			}
		}

		[$decimalSeparator, $thousandsSeparator] = $this->numberSeparators[$effectiveLanguage];

		return BalanceFormatter::format($raw, $decimalSeparator, $thousandsSeparator);
	}
}
