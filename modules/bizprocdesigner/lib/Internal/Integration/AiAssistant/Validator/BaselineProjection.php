<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentSettingValueCanonicalizer;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeClassifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeFingerprint;

/**
 * The published baseline as ONE projection with one matching rule (ALG-02), against the dozen and a half
 * fingerprint sets {@see ValidationContext} accumulated one per historical exception. Each of those sets answers
 * "is this exact payload published somewhere in the template"; this one answers "which published block is the
 * block in front of me", which is the question every exception actually asks and none of them could put.
 *
 * Built from the server-loaded PUBLISHED version alone - never from the draft and never from the graph under
 * validation ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory}) -
 * or a graph would author a payload and then collect its own authorisation.
 *
 * The sets are carried over one at a time, each with a test of its own, because each serves its own class of
 * historical templates and a set that changed its answer would cost that class its round-trip. Seven of them are
 * views of this projection already - {@see self::blockTypes()}, {@see self::verbatimStartTriggerReferences()},
 * {@see self::immutableFingerprints()}, {@see self::staticConsumerFingerprints()},
 * {@see self::staticConsumerReferences()}, {@see self::storageCodes()} and {@see self::creatorStorageCodes()} -
 * and the exception that has no set at all, the requiredness of a block the server wrote unchanged, is answered by
 * the rule itself ({@see ValidationContext::carriesBlockUnchanged()}).
 *
 * The line the transfer stops at is the portal. A set that is a pure reading of the published graph belongs here,
 * whatever taxonomy of node types it applies; a set that has to ASK the portal something - which restricted names
 * an activity declares, which document type a complex node fixes, how a wizard form parses - is assembled by
 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory} instead, which
 * is where this contour keeps its collaborators. Bringing those here would turn one matching rule into a walker of
 * half a dozen services.
 *
 * A view is not always the matching rule: a set anchored coarser than a block on purpose - a type, a fingerprint
 * without the node id - keeps that anchoring here, since narrowing it to id+type would refuse edits the agent is
 * meant to be able to make. What the projection gives them is one source of the published graph and one place to
 * read it, not one key.
 *
 * The same matching rule serves one graph that is NOT the published version: the graph the agent was HANDED
 * ({@see ValidationContext::$handed}). It authorises nothing on its own - what it answers is "the agent is
 * sending back what it was given" - and the two are kept apart at the point of use, never merged here. All three
 * questions the handed graph answers are put in one place, so the projection stays one rule and not two
 * ({@see ValidationContext::carriesBlockValue()}, {@see ValidationContext::carriesBlockIdentity()},
 * {@see ValidationContext::carriesBlockUnchanged()}).
 */
final class BaselineProjection
{
	private const TEMPLATE_ID_TOKEN = '{=Workflow:TemplateId}';

	/** @var array<string, list<AgentBlock>> published blocks keyed by the id+type identity of ALG-02 */
	private readonly array $blocks;

	/** @var array<string, true>|null the answer of {@see self::addressedBlockIds()}, walked once per projection */
	private ?array $addressedBlockIds = null;

	/** @var array<string, mixed>|null the answer of {@see self::storageSets()}, walked once per projection */
	private ?array $storageSets = null;

	/**
	 * @param iterable<AgentBlock> $blocks blocks of the published version, in any order
	 */
	public function __construct(iterable $blocks = [])
	{
		$indexed = [];
		foreach ($blocks as $block)
		{
			$indexed[self::identity($block->id, $block->type)][] = $block;
		}

		$this->blocks = $indexed;
	}

	public function isEmpty(): bool
	{
		return $this->blocks === [];
	}

	/**
	 * The published block an incoming one stands for, or null when there is none. Absence and ambiguity give the
	 * same answer on purpose (ALG-02 `match`): two published blocks sharing an identity leave no way to tell which
	 * of them an exception would be granted against, and guessing would hand the agent the more permissive one.
	 *
	 * The type is matched without regard to case, exactly as it is resolved everywhere else in this contour; the
	 * id rides verbatim, because it is an identifier the agent chose and the graph addresses connections by.
	 */
	public function match(?string $blockId, ?string $blockType): ?AgentBlock
	{
		if ($blockId === null || $blockId === '' || $blockType === null || $blockType === '')
		{
			return null;
		}

		$candidates = $this->blocks[self::identity($blockId, $blockType)] ?? [];

		return count($candidates) === 1 ? $candidates[0] : null;
	}

	/**
	 * Whether this very block already carries this very value for this setting in the published version
	 * (ALG-02 `carriedValueAllowed`). Anchored per block rather than per value: a value published on one node is
	 * not an authorisation to write it on another, so copying it into a new block is refused.
	 */
	public function carriedValueAllowed(
		?string $blockId,
		?string $blockType,
		string $settingName,
		mixed $value,
	): bool
	{
		$base = $this->match($blockId, $blockType);
		if ($base === null)
		{
			return false;
		}

		$baselineValues = self::valuesOf($base->settings);
		$name = mb_strtolower($settingName);

		return array_key_exists($name, $baselineValues)
			&& $baselineValues[$name] === self::normalize($value)
		;
	}

	/**
	 * Whether the block in front of the check is the one this projection carries, unchanged over its WHOLE settings
	 * set (ALG-02 `requirementExemptAllowed`) - the criterion the requiredness exemption rests on, and the one no
	 * per-value fingerprint could express. One changed setting takes the exemption away from all of them, because
	 * what is excused is the history the server wrote rather than a single value.
	 *
	 * Only the names the agent owns take part: $comparedNames carries the editable and carried settings of the
	 * block, while computed and server-managed values are not compared with the input at all - the server writes
	 * them, so a difference there says nothing about what the agent edited.
	 *
	 * Connections are not part of the identity and not part of this comparison: rewiring the graph around a block
	 * leaves the block itself untouched, and taking the exemption away for it would refuse an edit that never
	 * touched the settings the exemption is about.
	 *
	 * @param array<string, mixed> $submittedValues values as sent, keyed by the lower-cased setting name
	 * @param list<string> $comparedNames names that take part in the comparison, in any case
	 */
	public function requirementExemptAllowed(
		?string $blockId,
		?string $blockType,
		array $submittedValues,
		array $comparedNames,
	): bool
	{
		$base = $this->match($blockId, $blockType);
		if ($base === null)
		{
			return false;
		}

		return self::comparedSubset(self::valuesOf($base->settings), $comparedNames)
			=== self::comparedSubset(self::normalizeAll($submittedValues), $comparedNames)
		;
	}

	/**
	 * The types the published version is built of, keyed lower-cased - the set the reverse path lifts the EXCLUDED
	 * barrier of a reverse-visible CLASS against ({@see ValidationContext::$baselineBlockTypes}). Coarser than the
	 * matching rule on purpose, and that is why it is answered here rather than through {@see self::match()}: an
	 * agent edits the settings of the nodes it carries through, so anchoring the barrier per node would cost every
	 * such edit its round-trip.
	 *
	 * @return array<string, true>
	 */
	public function blockTypes(): array
	{
		$types = [];
		foreach ($this->blocks as $candidates)
		{
			foreach ($candidates as $block)
			{
				$types[mb_strtolower($block->type)] = true;
			}
		}

		return $types;
	}

	/**
	 * The published instances of the two agent start triggers, keyed by the reference fingerprint the graph pass
	 * looks them up by ({@see ValidationContext::$baselineVerbatimStartTriggerReferences}). Their settings carry no
	 * typed contract, so the whole instance - id, title, description and every setting verbatim - is the identity
	 * here ({@see AgentStartTriggerMatcher::referenceFingerprint()}), and a type-only admission would let an agent
	 * add, copy or alter one under the verbatim exception.
	 *
	 * @return array<string, true>
	 */
	public function verbatimStartTriggerReferences(): array
	{
		$references = [];
		foreach ($this->blocks as $candidates)
		{
			foreach ($candidates as $block)
			{
				if (AgentStartTriggerMatcher::matches($block->type))
				{
					$references[AgentStartTriggerMatcher::referenceFingerprint($block)] = true;
				}
			}
		}

		return $references;
	}

	/**
	 * The fingerprints of the published create/setuptemplate nodes, which are immutable once published
	 * ({@see ValidationContext::$baselineImmutableFingerprints}). Keyed by type and settings and NOT by node id:
	 * an agent may renumber the graph it returns, and an unchanged wizard node moved to another id is still the
	 * node the template published.
	 *
	 * @return array<string, true>
	 */
	public function immutableFingerprints(): array
	{
		$fingerprints = [];
		foreach ($this->blocks as $candidates)
		{
			foreach ($candidates as $block)
			{
				if (StorageNodeClassifier::isImmutable($block->type))
				{
					$fingerprints[StorageNodeFingerprint::of($block->type, $block->settings)] = true;
				}
			}
		}

		return $fingerprints;
	}

	/**
	 * The published read/write/delete nodes whose storage reference is anchored rather than editable: a static
	 * code, a code the barrier cannot read whole, or an address by StorageId
	 * ({@see ValidationContext::$baselineStaticNodeFingerprints}). Keyed by type and settings and not by node id,
	 * exactly as {@see self::immutableFingerprints()} is and for the same reason.
	 *
	 * @return array<string, true>
	 */
	public function staticConsumerFingerprints(): array
	{
		return $this->storageSets()['staticFingerprints'];
	}

	/**
	 * The same published nodes keyed by their block identity as well
	 * ({@see ValidationContext::$baselineStaticNodeReferences}). The set above authorises the sensitive reference;
	 * this one keeps an authorised fingerprint from being copied onto another node id.
	 *
	 * @return array<string, true>
	 */
	public function staticConsumerReferences(): array
	{
		return $this->storageSets()['staticReferences'];
	}

	/**
	 * Storage codes the published version declares ({@see ValidationContext::$baselineStorageCodes}): the codes of
	 * its create/setuptemplate nodes AND the token codes its consumers use, since the storage behind a consumer may
	 * have been created by the setup wizard rather than by a node of the graph.
	 *
	 * @return list<string>
	 */
	public function storageCodes(): array
	{
		return $this->storageSets()['codes'];
	}

	/**
	 * The narrower set of codes the published CREATE nodes declare
	 * ({@see ValidationContext::$baselineCreatorStorageCodes}) - the one that means "the template owns this code".
	 * A consumer token code proves no ownership, so the two sets never merge; a create node addressing storage by
	 * StorageId contributes nothing here either, because the runtime prefers the id over the code and such a node
	 * owns nothing under the code it names.
	 *
	 * @return list<string>
	 */
	public function creatorStorageCodes(): array
	{
		return $this->storageSets()['creatorCodes'];
	}

	/**
	 * The columns the published CREATE nodes declare, keyed by the NORMALISED code they declare them for - the
	 * third source of the columns a value map of a storage consumer is judged against (BRIEF § 3.4,
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageColumnSchemaResolver}).
	 *
	 * Read off the same nodes {@see self::creatorStorageCodes()} is, so what the template is said to own and what
	 * its columns are said to be come from one classification. Two published creators of one code that disagree
	 * leave the code out entirely: the published version is then a conflict this projection may not resolve, and
	 * inventing a winner would judge a value map against columns no node declared.
	 *
	 * @return array<string, mixed> normalised code => the `SelectedFields` value, as published
	 */
	public function creatorStorageColumns(): array
	{
		return $this->storageSets()['creatorColumns'];
	}

	/**
	 * The five storage sets in one walk, memoised whatever they turn out to be. They are views of this projection
	 * for the same reason {@see self::immutableFingerprints()} is - each is a pure reading of the published graph
	 * through the node taxonomy, with nothing asked of the portal - and they are computed together because one
	 * node feeds several of them and the classification is the expensive part.
	 *
	 * @return array{
	 *     staticFingerprints: array<string, true>,
	 *     staticReferences: array<string, true>,
	 *     codes: list<string>,
	 *     creatorCodes: list<string>,
	 *     creatorColumns: array<string, mixed>,
	 * }
	 */
	private function storageSets(): array
	{
		if ($this->storageSets !== null)
		{
			return $this->storageSets;
		}

		$staticFingerprints = [];
		$staticReferences = [];
		$codes = [];
		$creatorCodes = [];
		$creatorColumns = [];
		$creatorContracts = [];
		$conflictingColumnCodes = [];

		foreach ($this->blocks as $candidates)
		{
			foreach ($candidates as $block)
			{
				$declaredCode = StorageNodeClassifier::extractStorageCode($block->settings);

				if (StorageNodeClassifier::isImmutable($block->type))
				{
					if ($declaredCode === null || $declaredCode === '')
					{
						continue;
					}

					$codes[] = $declaredCode;

					// The id-addressing predicate answers here exactly as it answers for a consumer below: a
					// create node carrying a StorageId reconfigures the storage that id points at, not the one
					// its StorageCode names, so the code proves no ownership and must not open the inheritance
					// of the admission policy. The declared set keeps the code regardless - it only makes a
					// per-template token code editable, and dropping it would cost an unchanged template its
					// round-trip.
					if (
						StorageNodeClassifier::isStorageCreator($block->type)
						&& !self::addressesStorageById($block->settings)
					)
					{
						$creatorCodes[] = $declaredCode;

						$normalized = StorageReferenceGuard::normalizeCode($declaredCode);
						$contract = StorageNodeFingerprint::columnContractOf($block->settings);
						if (
							array_key_exists($normalized, $creatorColumns)
							&& ($creatorContracts[$normalized] ?? null) !== $contract
						)
						{
							$conflictingColumnCodes[$normalized] = true;
						}

						$creatorColumns[$normalized] = StorageNodeClassifier::extractStorageColumns($block->settings);
						$creatorContracts[$normalized] = $contract;
					}

					continue;
				}

				if (!StorageNodeClassifier::isConsumer($block->type))
				{
					continue;
				}

				// An opaque code is anchored by the fingerprint as well. It would otherwise fall to the declared
				// codes, where {@see \Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext::indexCodes()}
				// drops it for being unreadable - anchoring the published node nowhere and costing an unchanged
				// template its round-trip. "The barrier does not read this code" therefore always means "anchor it
				// by fingerprint", never "drop it from both sets".
				$hasStaticCode = $declaredCode !== null
					&& (
						self::isStaticCode($declaredCode)
						|| !StorageReferenceGuard::isFullyInterpretableCode($declaredCode)
					)
				;

				if ($hasStaticCode || self::addressesStorageById($block->settings))
				{
					$staticFingerprints[StorageNodeFingerprint::of($block->type, $block->settings)] = true;
					$staticReferences[StorageNodeFingerprint::referenceOf(
						$block->id,
						$block->type,
						$block->settings,
					)] = true;
				}
				elseif ($declaredCode !== null && $declaredCode !== '')
				{
					// A pure token code of a published consumer: its storage may predate the graph.
					$codes[] = $declaredCode;
				}
			}
		}

		return $this->storageSets = [
			'staticFingerprints' => $staticFingerprints,
			'staticReferences' => $staticReferences,
			'codes' => array_values(array_unique($codes)),
			'creatorCodes' => array_values(array_unique($creatorCodes)),
			'creatorColumns' => array_diff_key($creatorColumns, $conflictingColumnCodes),
		];
	}

	/**
	 * Whether the code is anchored by the node fingerprint rather than being an editable per-template token code.
	 *
	 * Byte-wise on purpose: anything this check does not recognise as the canonical token is treated as static.
	 * The guard judges what a code EXPANDS to ({@see StorageReferenceGuard::isFullyInterpretableCode()}); erring
	 * towards "static" here can only narrow what the agent may edit, never widen it.
	 */
	private static function isStaticCode(string $code): bool
	{
		if ($code === '')
		{
			return false;
		}

		return !str_contains($code, self::TEMPLATE_ID_TOKEN)
			|| str_contains(str_replace(self::TEMPLATE_ID_TOKEN, '', $code), '{=');
	}

	/**
	 * The very predicate the guard applies in ALG-02 rule 1, so the baseline collection and the refusal cannot
	 * drift apart: what the guard refuses as an id-addressed node is exactly what is anchored here.
	 */
	private static function addressesStorageById(AgentSettingCollection $settings): bool
	{
		return StorageReferenceGuard::addressesStorageById(StorageNodeClassifier::extractStorageId($settings));
	}

	/**
	 * The block ids the published version addresses in its own settings - what an expression of it names, whether
	 * or not the graph carries a block under that id. It answers a question the block list cannot: the runtime
	 * resolves a reference by walking the whole activity tree, so a template may legitimately address the child of
	 * a complex node's sub-action, and the projection the agent is handed carries no name for such a child at all
	 * (the shipped system agents do exactly this). Anchored to no block on purpose, exactly as
	 * {@see self::blockTypes()} is: an agent moves a published value onto a node of its own, and holding the id to
	 * the block that published it would cost that edit its round-trip.
	 *
	 * What it is NOT is a permission to invent a reference: an id neither the graph nor the published version
	 * names is refused, which is the whole of what the check can honestly claim.
	 *
	 * @return array<string, true>
	 */
	public function addressedBlockIds(): array
	{
		if ($this->addressedBlockIds !== null)
		{
			return $this->addressedBlockIds;
		}

		$ids = [];
		foreach ($this->blocks as $candidates)
		{
			foreach ($candidates as $block)
			{
				foreach ($block->settings as $setting)
				{
					/** @var AgentSetting $setting */
					self::collectAddressedIds($setting->value, $ids);
				}
			}
		}

		return $this->addressedBlockIds = $ids;
	}

	/**
	 * @param array<string, true> $ids
	 */
	private static function collectAddressedIds(mixed $value, array &$ids): void
	{
		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				self::collectAddressedIds((string)$key, $ids);
				self::collectAddressedIds($item, $ids);
			}

			return;
		}

		if (!is_string($value) || !str_contains($value, '{='))
		{
			return;
		}

		foreach (\CBPActivity::findExpressions($value) as $expression)
		{
			$source = SourceType::getObjectSourceType((string)$expression['object'], (string)$expression['field']);
			if (($source[0] ?? null) === SourceType::Activity && is_string($source[1] ?? null))
			{
				$ids[$source[1]] = true;
			}
		}
	}

	private static function identity(string $blockId, string $blockType): string
	{
		return strlen($blockId) . ':' . $blockId . '|' . mb_strtolower($blockType);
	}

	/**
	 * The published values of one block, keyed by the lower-cased name and already canonical, so the two sides of
	 * every comparison reach it through the same function ({@see AgentSettingValueCanonicalizer}).
	 *
	 * @return array<string, mixed>
	 */
	private static function valuesOf(AgentSettingCollection $settings): array
	{
		$values = [];
		foreach ($settings as $setting)
		{
			/** @var AgentSetting $setting */
			$values[mb_strtolower($setting->name)] = self::normalize($setting->value);
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function normalizeAll(array $values): array
	{
		$normalized = [];
		foreach ($values as $name => $value)
		{
			$normalized[mb_strtolower((string)$name)] = self::normalize($value);
		}

		return $normalized;
	}

	/**
	 * The compared names alone, key-sorted: the two sides are built in the order their own source lists the
	 * settings in, and an identical set written in two orders is the same set.
	 *
	 * @param array<string, mixed> $values
	 * @param list<string> $comparedNames
	 * @return array<string, mixed>
	 */
	private static function comparedSubset(array $values, array $comparedNames): array
	{
		$keys = [];
		foreach ($comparedNames as $name)
		{
			$keys[mb_strtolower($name)] = true;
		}

		$subset = array_intersect_key($values, $keys);
		ksort($subset);

		return $subset;
	}

	private static function normalize(mixed $value): mixed
	{
		return AgentSettingValueCanonicalizer::canonicalize($value);
	}
}
