<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingVariantDto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingChangeSet;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Reads the `fields` of a write of the embedding as the change set of the domain. Mirror of
 * {@see EmbeddingDtoMapper} on the writing side.
 *
 * Like the write mapping of the form itself ({@see FormWriteRequestMapper}), it works on the keys
 * the client actually sent ({@see FieldsStructure::getItems()}) rather than on the DTO those keys
 * would fill: a partial write stands on telling an absent key from a key holding null, and a filled
 * DTO holds no such difference. The published format is the one of {@see EmbeddingDto}: the same
 * nested settings, under the same names, for reading and for writing.
 *
 * Four kinds of refusal come out of here, told apart by what is wrong:
 *
 * - a path the published projection does not have at all - a misspelled setting, anything unknown -
 *   is answered the way the framework answers an unavailable property, raised at once as
 *   {@see UnknownDtoPropertyException};
 * - a published variant carrying no settings - the inline one - is refused at the variant: nothing
 *   below it exists in this contract, so there is no deeper address to name;
 * - a path under a variant that is not a setting of that variant - the button of the automatic
 *   variant, the delay of the one shown on click, the embed code, the preview link, the allowed
 *   values - is refused at its own address;
 * - a path the projection publishes outside the variants - the activity of the form, the readiness
 *   of its resources, its public link - is refused as published for reading only.
 *
 * The last three are collected, and every violation of the document travels out together in one
 * {@see FormValidationException}, each error coded by the input path it belongs to. An unknown
 * setting is refused rather than dropped without a word: the builder filters such keys silently,
 * which would answer an external client "stored" to a write that never happened.
 *
 * Nothing of the domain is decided here. Whether a value is one of the ones the portal allows is
 * asked of the dictionary of the portal by the write scenario, which is the only side that may ask
 * it; the transport reads types.
 */
final class EmbeddingWriteRequestMapper
{
	use ReadsWrittenScalars;

	/**
	 * The change set the fields of a write of the embedding describe.
	 *
	 * @throws UnknownDtoPropertyException when the input names a path no projection publishes
	 * @throws FormValidationException when the document breaks the write contract; all violations
	 *         are carried at once
	 */
	public function mapChangeSet(FieldsStructure $fields): EmbeddingChangeSet
	{
		$errors = [];
		$values = $this->valuesOf($fields->getItems(), '', $errors);

		if ($errors !== [])
		{
			throw new FormValidationException(...$errors);
		}

		return new EmbeddingChangeSet($values);
	}

	/**
	 * One level of the input document, keyed by the path each key stands for. An object standing
	 * above the settings is read through its own properties, so nothing but settings comes out.
	 *
	 * @param array<string|int, mixed> $node
	 * @param Error[] $errors
	 * @return array<string, mixed>
	 */
	private function valuesOf(array $node, string $prefix, array &$errors): array
	{
		$values = [];
		foreach ($node as $property => $value)
		{
			$path = self::pathOf($prefix, (string)$property);

			if (EmbeddingChangeSet::isWritablePath($path))
			{
				$refusalsBefore = count($errors);
				$setting = $this->settingOf($path, $value, $errors);
				if (count($errors) === $refusalsBefore)
				{
					$values[$path] = $setting;
				}

				continue;
			}

			if (!EmbeddingChangeSet::carriesSettings($path))
			{
				$this->refuseOutsideTheWriteContract($path, $errors);

				continue;
			}

			if (!is_array($value))
			{
				$errors[] = new Error(
					$value === null
						? sprintf('`%s` is cleared through its own settings, not as a whole', $path)
						: sprintf('`%s` expects an object', $path),
					$path,
				);

				continue;
			}

			$values = array_merge($values, $this->valuesOf($value, $path, $errors));
		}

		return $values;
	}

	/**
	 * A setting of the contract, read as the kind the model gives it. Null clears the setting and
	 * needs no kind at all.
	 *
	 * @param Error[] $errors
	 */
	private function settingOf(string $path, mixed $value, array &$errors): mixed
	{
		if ($value === null)
		{
			return null;
		}

		return match (EmbeddingChangeSet::kindOf($path))
		{
			EmbeddingChangeSet::KIND_FLAG => self::asBool($value, $path, $errors),
			EmbeddingChangeSet::KIND_INTEGER => self::asInt($value, $path, $errors),
			default => self::asString($value, $path, $errors),
		};
	}

	/**
	 * Refuses a path the write contract does not carry, by what the published projection says about
	 * it: a path a client can read back is refused as a value that may not be written, and one the
	 * projection has never heard of is answered as an unavailable property, the way the framework
	 * answers it.
	 *
	 * @param Error[] $errors
	 * @throws UnknownDtoPropertyException
	 */
	private function refuseOutsideTheWriteContract(string $path, array &$errors): void
	{
		$projection = self::projection();
		if ($projection->fieldAt($path) === null)
		{
			throw new UnknownDtoPropertyException($projection->name(), $path);
		}

		$variant = $this->variantOf($path);
		if ($variant === null)
		{
			$errors[] = new Error(sprintf('`%s` is published for reading only', $path), $path);

			return;
		}

		$errors[] = $path === $variant
			? new Error(sprintf('`%s` carries no settings of its own', $path), $path)
			: new Error(sprintf('`%s` is no setting of the `%s` variant', $path, $variant), $path)
		;
	}

	/**
	 * The variant the path stands under, or null for a path outside the variants. The published
	 * projection answers which of its properties is a variant, so a variant is never a list of names
	 * kept here.
	 */
	private function variantOf(string $path): ?string
	{
		$root = explode('.', $path, 2)[0];

		return self::projection()->contentTypeAt($root) === EmbeddingVariantDto::class ? $root : null;
	}

	/**
	 * The published format the input is read against: the same nested settings, under the same
	 * names, for reading and for writing.
	 */
	private static function projection(): PublishedProjection
	{
		return PublishedProjection::of(EmbeddingDto::class);
	}

	private static function pathOf(string $prefix, string $property): string
	{
		return $prefix === '' ? $property : $prefix . '.' . $property;
	}
}
