<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\Embedding;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingSelection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Crm\V2\Internal\Exception\WebForm\EmbeddingSelectionException;
use Bitrix\Main\Localization\Loc;

/**
 * Selects one embedding value after the existing read scenario has completed all of its checks.
 *
 * @internal
 */
final class EmbeddingSelectionReader
{
	private const BUTTON_TYPE_DEFAULT = 'button';

	private const SUPPORTED_VARIANTS = [
		EmbeddingVariant::INLINE,
		EmbeddingVariant::CLICK,
		EmbeddingVariant::AUTO,
		EmbeddingSelection::LINK,
	];

	private readonly \Closure $embeddingLoader;
	private readonly \Closure $clickSnippetBuilder;
	private readonly \Closure $autoSnippetBuilder;
	private readonly \Closure $defaultButtonTextLoader;

	/**
	 * @param null|\Closure(int, int): Embedding $embeddingLoader
	 * @param null|\Closure(int, string, string): ?string $clickSnippetBuilder
	 * @param null|\Closure(int, int): ?string $autoSnippetBuilder
	 * @param null|\Closure(): string $defaultButtonTextLoader
	 */
	public function __construct(
		?\Closure $embeddingLoader = null,
		?\Closure $clickSnippetBuilder = null,
		?\Closure $autoSnippetBuilder = null,
		?\Closure $defaultButtonTextLoader = null,
	)
	{
		$this->embeddingLoader = $embeddingLoader ?? static fn (int $userId, int $formId): Embedding =>
			(new EmbeddingReader())->getEmbedding($userId, $formId)
		;
		$this->clickSnippetBuilder = $clickSnippetBuilder ?? static fn (
			int $formId,
			string $buttonText,
			string $buttonType,
		): ?string => (new EmbeddingSnippetBuilder())->buildClick($formId, $buttonText, $buttonType);
		$this->autoSnippetBuilder = $autoSnippetBuilder ?? static fn (int $formId, int $delay): ?string =>
			(new EmbeddingSnippetBuilder())->buildAuto($formId, $delay)
		;
		$this->defaultButtonTextLoader = $defaultButtonTextLoader ?? static fn (): string =>
			self::defaultButtonText()
		;
	}

	public function get(
		int $userId,
		int $formId,
		string $variant,
		?string $buttonText,
		?string $buttonType,
		?int $delay,
	): EmbeddingSelection
	{
		$embedding = ($this->embeddingLoader)($userId, $formId);

		$this->assertSupportedVariant($variant);
		$this->assertOverridesBelongToVariant($variant, $buttonText, $buttonType, $delay);

		return match ($variant)
		{
			EmbeddingVariant::INLINE => new EmbeddingSelection($variant, code: $embedding->inline->code),
			EmbeddingVariant::CLICK => $this->selectClick($embedding, $buttonText, $buttonType),
			EmbeddingVariant::AUTO => $this->selectAuto($embedding, $delay),
			EmbeddingSelection::LINK => new EmbeddingSelection($variant, url: $embedding->publicUrl),
		};
	}

	private function selectClick(
		Embedding $embedding,
		?string $buttonText,
		?string $buttonType,
	): EmbeddingSelection
	{
		if ($buttonText === null && $buttonType === null)
		{
			return new EmbeddingSelection(EmbeddingVariant::CLICK, code: $embedding->click->code);
		}

		$buttonText ??= $embedding->click->settings?->buttonText ?? ($this->defaultButtonTextLoader)();
		$buttonType ??= self::BUTTON_TYPE_DEFAULT;

		return new EmbeddingSelection(
			EmbeddingVariant::CLICK,
			code: ($this->clickSnippetBuilder)($embedding->formId, $buttonText, $buttonType),
		);
	}

	private function selectAuto(Embedding $embedding, ?int $delay): EmbeddingSelection
	{
		if ($delay === null)
		{
			return new EmbeddingSelection(EmbeddingVariant::AUTO, code: $embedding->auto->code);
		}

		if (!in_array($delay, $embedding->auto->options?->delays ?? [], true))
		{
			throw new EmbeddingSelectionException(
				'delay',
				sprintf('Embedding delay `%d` is not supported', $delay),
			);
		}

		return new EmbeddingSelection(
			EmbeddingVariant::AUTO,
			code: ($this->autoSnippetBuilder)($embedding->formId, $delay),
		);
	}

	private function assertSupportedVariant(string $variant): void
	{
		if (!in_array($variant, self::SUPPORTED_VARIANTS, true))
		{
			throw new EmbeddingSelectionException(
				'variant',
				sprintf('Unknown embedding variant `%s`', $variant),
			);
		}
	}

	/**
	 * Each override is refused under the name it arrived under: the caller answering over a
	 * transport names the key the client sent, and one refusal of the group is not another.
	 */
	private function assertOverridesBelongToVariant(
		string $variant,
		?string $buttonText,
		?string $buttonType,
		?int $delay,
	): void
	{
		$foreignOverrides = [
			'buttonText' => $buttonText !== null && $variant !== EmbeddingVariant::CLICK,
			'buttonType' => $buttonType !== null && $variant !== EmbeddingVariant::CLICK,
			'delay' => $delay !== null && $variant !== EmbeddingVariant::AUTO,
		];

		foreach ($foreignOverrides as $fieldName => $isForeign)
		{
			if ($isForeign)
			{
				throw new EmbeddingSelectionException(
					$fieldName,
					sprintf('`%s` does not belong to embedding variant `%s`', $fieldName, $variant),
				);
			}
		}
	}

	/**
	 * The caption legacy puts on a button of its own, read out of the language file holding it.
	 *
	 * The phrase belongs to {@see \Bitrix\Crm\WebForm\Script} and is loaded by the include of that
	 * class, which is why reading it used to work without asking for it. This scenario needs the
	 * phrase and not the class, so it loads the file the phrase is in.
	 */
	private static function defaultButtonText(): string
	{
		$messages = Loc::loadLanguageFile(dirname(__DIR__, 4) . '/webform/script.php');

		return (string)($messages['CRM_WEBFORM_SCRIPT_BUTTON_TEXT'] ?? '');
	}
}
