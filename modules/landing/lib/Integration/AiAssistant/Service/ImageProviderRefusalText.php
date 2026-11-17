<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\AI\Tuning;
use Bitrix\Landing\Connector\Ai;
use Bitrix\Landing\Copilot\Connector\AI\Image;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * The hint for a user whose image provider did not accept the request, in the language of the current hit.
 *
 * The name of the provider is set by a third-party application: it reaches the chat and the answer to the agent
 * only as plain text of a limited length. On top of the rule of the titles in the messages of the bot it goes
 * through an allow list: the chat runs the text through the markdown converter, where a backslash, an asterisk,
 * an underscore, angle brackets or a scheme of a link become markup, and the invisible format characters
 * are not seen by a person at all. A name keeps letters, digits, spaces and neutral punctuation. A dot stays only
 * between digits, as in a version: the chat makes a link of www with a dot even inside quotes.
 *
 * The advice to change the provider is given only where it can work: the setting has more than one option
 * and FLUX, which ignores that setting, is off. In every other case the user is told to try later.
 */
final class ImageProviderRefusalText
{
	public const PROVIDER_MAX_LENGTH = 100;

	private const PROVIDER_ELLIPSIS = '…';

	/**
	 * Everything a name must not keep, spaces and a dot outside a number included: a run of it becomes a single space.
	 */
	private const PROVIDER_FORBIDDEN_PATTERN = '/(?:[^\p{L}\p{N}.,+()-]|(?<!\p{N})\.|\.(?!\p{N}))+/u';

	private function __construct()
	{
	}

	/**
	 * The name of the provider made safe to show; empty when nothing is left of the raw name.
	 */
	public static function provider(string $raw): string
	{
		// a name that is not valid UTF-8 cannot be checked, so nothing of it is shown
		$provider = trim((string)preg_replace(self::PROVIDER_FORBIDDEN_PATTERN, ' ', AiSiteChatPlainText::title($raw)));
		if (mb_strlen($provider) <= self::PROVIDER_MAX_LENGTH)
		{
			return $provider;
		}

		$cutLength = self::PROVIDER_MAX_LENGTH - mb_strlen(self::PROVIDER_ELLIPSIS);

		return rtrim(mb_substr($provider, 0, $cutLength)) . self::PROVIDER_ELLIPSIS;
	}

	/**
	 * @param string $rawProvider Name of the provider as it was stored.
	 * @param bool|null $canChangeProvider Whether to advise changing the provider; null - find out from the portal.
	 *
	 * @return string
	 */
	public static function build(string $rawProvider, ?bool $canChangeProvider = null): string
	{
		$canChangeProvider ??= self::canChangeProvider();
		$provider = self::provider($rawProvider);

		if ($provider === '')
		{
			return (string)Loc::getMessage(
				$canChangeProvider
					? 'LANDING_AI_IMAGE_PROVIDER_REFUSAL_TEXT_NO_NAME'
					: 'LANDING_AI_IMAGE_PROVIDER_REFUSAL_RETRY_TEXT_NO_NAME'
			);
		}

		return (string)Loc::getMessage(
			$canChangeProvider
				? 'LANDING_AI_IMAGE_PROVIDER_REFUSAL_TEXT'
				: 'LANDING_AI_IMAGE_PROVIDER_REFUSAL_RETRY_TEXT',
			['#PROVIDER#' => $provider],
		);
	}

	private static function canChangeProvider(): bool
	{
		try
		{
			if (!Loader::includeModule('ai') || Image::getFluxEngineCode() !== null)
			{
				return false;
			}

			$item = (new Tuning\Manager())->getItem(Ai::TUNING_CODE_SITE_IMAGE_PROVIDER);

			return $item !== null && count($item->getOptions()) > 1;
		}
		catch (\Throwable)
		{
			// the hint must reach the user anyway, and the advice to try later holds on any portal
			return false;
		}
	}
}
