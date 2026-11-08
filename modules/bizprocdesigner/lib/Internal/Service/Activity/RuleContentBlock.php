<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ContentBlockResolver;
use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\RuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Trait\NodeActionResolver;

/**
 * The single owner of the derived content block of the rules of a node: the one place that walks the rule
 * structure looking for action constructions, and the one place that decides whether such a construction
 * gets a block at all. A construction of any other kind leaves the walk without one, whatever it arrived
 * holding.
 *
 * The block is display-only and is never authoritative in a saved template: the read transport recalculates
 * it from the current configuration of the nested activity, and the write transport cuts the client copy out
 * before it reaches the properties of the node. A value that did survive in an old template is therefore
 * neither read nor trusted - only replaced.
 */
final class RuleContentBlock
{
	use NodeActionResolver;

	/** Key the block travels under, both in the transport of the rules and in a template that still carries one. */
	private const CONTENT_BLOCK_KEY = 'ContentBlock';

	/**
	 * How many action constructions of one request may be recalculated. Each recalculation loads the file of
	 * the nested activity and calls its provider, while nothing on the read path bounds the number of
	 * constructions a node may carry. Beyond the budget the key is dropped instead of being resolved, so a
	 * huge payload costs the panel its preview and not the request.
	 *
	 * The value mirrors AgentBlocksValidator::MAX_BLOCKS: one node cannot legitimately carry more actions
	 * than the whole scheme carries blocks.
	 */
	public const MAX_CONTENT_BLOCK_CONSTRUCTIONS = 256;

	/**
	 * What is left of the budget of this instance, i.e. of the request: a node whose rules and relations are
	 * both recalculated shares one budget between them instead of getting one per container.
	 */
	private int $remainingConstructions = self::MAX_CONTENT_BLOCK_CONSTRUCTIONS;

	/**
	 * Context every construction of this instance is resolved against, one per instance exactly as the budget
	 * above, so the labels of a node whose rules and relations are both recalculated are resolved once.
	 */
	private readonly ?ContentBlockContext $context;

	/**
	 * @param ?ContentBlockContext $context Built by
	 *     {@see \Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory}; null
	 *     leaves the preview saying what it said before the readable form existed.
	 * @param ?array $nodeDocumentType Document type the node hosting these rules works in, when it is not the
	 *     one of the template: its nested actions act on that document, so a reference to a document field in
	 *     one of them names a field of it. Null leaves every construction named in the type the context was
	 *     built in.
	 */
	public function __construct(?ContentBlockContext $context = null, ?array $nodeDocumentType = null)
	{
		$this->context = $nodeDocumentType === null || $nodeDocumentType === []
			? $context
			: $context?->forDocumentType($nodeDocumentType)
		;
	}

	/**
	 * Recalculates the block of every action construction of one rule dictionary of a node, admitting only
	 * the activity types the dictionary of actions of that very container offers - the one the same request
	 * has just built: the actions the node offers for its rules, the single action it declares for its
	 * relations.
	 *
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary Rules of the node, keyed by container name.
	 *     Mutated in place: the constructions carry the recalculated block afterwards.
	 * @param array<string, mixed> $actionEntryDtoDictionary Actions admitted for that dictionary, keyed by
	 *     activity class.
	 */
	public function resolveForNode(array $portRuleDtoDictionary, array $actionEntryDtoDictionary): void
	{
		$searcher = Container::instance()->getActivitySearcherService();

		$offeredTypes = [];
		foreach (array_keys($actionEntryDtoDictionary) as $activityClass)
		{
			$offeredTypes[$searcher->normalizeActivityCode((string)$activityClass)] = true;
		}

		$this->resolveInDictionary(
			$portRuleDtoDictionary,
			static fn(string $activityType): bool
				=> isset($offeredTypes[$searcher->normalizeActivityCode($activityType)]),
		);
	}

	/**
	 * The same recalculation for a single rule saved on its own, admitting an action of the node the container
	 * names as its host - named by the payload here, while the read path answers for the node it was opened
	 * on. Declaring itself a node action stays necessary and stops being enough: the type and the properties
	 * of a nested activity come straight from the payload there, and no other check of this request confines
	 * them to a catalog at all.
	 *
	 * A container naming no host node admits nothing at all, so such a payload reaches no provider: that is an
	 * absent block and not a refused request, and the rule is saved either way.
	 *
	 * The catalog of the node is built inside the admission, and at most once per call: assembling it walks
	 * every node action of the portal, while a container carrying no action at all - the very load the panel
	 * submits when an action is added - has nothing to admit and must pay no walk.
	 *
	 * @param array $documentType Document type of the request, the one its access has already been checked on.
	 */
	public function resolveForRule(PortRuleDto $portRuleDto, array $documentType): void
	{
		$searcher = Container::instance()->getActivitySearcherService();
		$offeredTypes = null;

		$this->resolveInDictionary(
			[$portRuleDto],
			function (string $activityType) use ($portRuleDto, $documentType, $searcher, &$offeredTypes): bool
			{
				if (!$this->isNodeActionBackingType($activityType))
				{
					return false;
				}

				$offeredTypes ??= $this->resolveHostNodeActionTypes($portRuleDto, $documentType);

				return isset($offeredTypes[$searcher->normalizeActivityCode($activityType)]);
			},
		);
	}

	/**
	 * Cuts the client copy of the block out of the raw rules payload of a save request, whatever construction
	 * carries it. Nothing else of the nested activity is touched: its type, document, name, return properties
	 * and own properties reach the node exactly as they were submitted.
	 *
	 * @param array<string, mixed> $rawRuleDictionary Rules as the request carries them, i.e. nested arrays of
	 *     the shape {portId, ruleCards[].constructions[].expression.activityData}.
	 * @return array<string, mixed> The same payload without the key.
	 */
	public function stripFromRawRules(array $rawRuleDictionary): array
	{
		foreach ($rawRuleDictionary as &$ruleCollection)
		{
			if (!is_array($ruleCollection) || !is_array($ruleCollection['ruleCards'] ?? null))
			{
				continue;
			}

			foreach ($ruleCollection['ruleCards'] as &$ruleCard)
			{
				if (!is_array($ruleCard) || !is_array($ruleCard['constructions'] ?? null))
				{
					continue;
				}

				foreach ($ruleCard['constructions'] as &$construction)
				{
					if (
						is_array($construction)
						&& is_array($construction['expression'] ?? null)
						&& is_array($construction['expression']['activityData'] ?? null)
					)
					{
						unset($construction['expression']['activityData'][self::CONTENT_BLOCK_KEY]);
					}
				}
				unset($construction);
			}
			unset($ruleCard);
		}
		unset($ruleCollection);

		return $rawRuleDictionary;
	}

	/**
	 * Normalized codes of the actions the host node of the container offers, empty when it names no node.
	 * Assembled by the same service and the same document type of the request the read path uses; which node
	 * it is assembled for is named by the container itself.
	 *
	 * @return array<string, true>
	 */
	private function resolveHostNodeActionTypes(PortRuleDto $portRuleDto, array $documentType): array
	{
		$hostNodeType = $this->resolveHostNodeType($portRuleDto);
		if ($hostNodeType === null)
		{
			return [];
		}

		$searcher = Container::instance()->getActivitySearcherService();
		$nodeActionCollection = Container::instance()
			->getComplexActivityService()
			->getCorrespondingNodeActionActivityByName(
				$searcher->normalizeActivityCode($hostNodeType),
				$documentType,
			)
		;

		$offeredTypes = [];
		foreach ($nodeActionCollection as $nodeAction)
		{
			$offeredTypes[$searcher->normalizeActivityCode((string)$nodeAction->getClass())] = true;
		}

		return $offeredTypes;
	}

	/**
	 * Type of the node hosting the container, as the base settings construction of the container names it -
	 * the construction the unified panel carries the node itself in. One carrying an actionId describes a
	 * child activity of its own instead, exactly as
	 * {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand} reads it.
	 */
	private function resolveHostNodeType(PortRuleDto $portRuleDto): ?string
	{
		foreach ($portRuleDto->rules as $ruleDto)
		{
			if (!$ruleDto instanceof RuleDto)
			{
				continue;
			}

			foreach ($ruleDto->constructions as $construction)
			{
				if (
					!$construction instanceof ConstructionDto
					|| $construction->constructionType !== ConstructionType::BASE_SETTINGS
					|| !$construction->expression instanceof BaseSettingsExpressionDto
					|| $construction->expression->actionId !== null
				)
				{
					continue;
				}

				$activityData = $construction->expression->activityData;
				$activityType = is_array($activityData) ? ($activityData['Type'] ?? null) : null;
				if (is_string($activityType) && $activityType !== '')
				{
					return $activityType;
				}
			}
		}

		return null;
	}

	/**
	 * Constructions whose activity data may reach the client holding a block. An ACTION is the only one whose
	 * block is recalculated here; a FILTER and a base-settings construction get theirs from the shared save
	 * pipeline instead ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}),
	 * which answers for the activity it saved and not for the request that submitted it. Nothing draws those
	 * two, so the key is cut rather than recalculated: the panel loses nothing and the block of a construction
	 * nobody admitted does not travel.
	 */
	private function carriesBlock(ConstructionDto $construction): bool
	{
		return match ($construction->constructionType)
		{
			ConstructionType::ACTION, ConstructionType::FILTER
				=> $construction->expression instanceof ActionExpressionDto,
			ConstructionType::BASE_SETTINGS
				=> $construction->expression instanceof BaseSettingsExpressionDto,
			default => false,
		};
	}

	/**
	 * @param iterable<PortRuleDto> $portRuleDtoDictionary
	 * @param callable(string): bool $isTypeAdmitted Whether the provider of that activity type may be called.
	 */
	private function resolveInDictionary(iterable $portRuleDtoDictionary, callable $isTypeAdmitted): void
	{
		foreach ($portRuleDtoDictionary as $portRuleDto)
		{
			if (!$portRuleDto instanceof PortRuleDto)
			{
				continue;
			}

			foreach ($portRuleDto->rules as $ruleDto)
			{
				if (!$ruleDto instanceof RuleDto)
				{
					continue;
				}

				foreach ($ruleDto->constructions as $construction)
				{
					if (!$construction instanceof ConstructionDto || !$this->carriesBlock($construction))
					{
						continue;
					}

					$activityData = $construction->expression->activityData;
					if (!is_array($activityData))
					{
						continue;
					}

					// An absent block is an absent key and not a kept one: a stale copy of a title the user
					// has since cleared must not outlive the recalculation.
					unset($activityData[self::CONTENT_BLOCK_KEY]);

					if ($construction->constructionType === ConstructionType::ACTION)
					{
						$contentBlock = $this->resolveContentBlock($activityData, $isTypeAdmitted);
						if ($contentBlock !== null)
						{
							$activityData[self::CONTENT_BLOCK_KEY] = $contentBlock->toArray();
						}
					}

					$construction->expression->activityData = $activityData;
				}
			}
		}
	}

	/**
	 * The block declared by the nested activity, or null when it declares none - and when the arguments the
	 * request carries are not the ones the resolver is declared with.
	 *
	 * Both the type and the properties of a nested activity are chosen by the client, and the resolver takes
	 * them as `string` and `array` under strict types, so a type arriving as an array would raise a TypeError
	 * outside its own fail-closed catch. Checked here instead, the same normalisation the resolver applies to
	 * a whole template.
	 *
	 * The budget is taken here, and only right before a provider is actually called: it bounds the cost of
	 * loading the files of nested activities, and a construction refused above has loaded none.
	 *
	 * @param callable(string): bool $isTypeAdmitted
	 */
	private function resolveContentBlock(array $activityData, callable $isTypeAdmitted): ?ContentBlock
	{
		$activityType = $activityData['Type'] ?? null;
		$properties = $activityData['Properties'] ?? null;

		if (!is_string($activityType) || $activityType === '' || !is_array($properties))
		{
			return null;
		}

		if (!$isTypeAdmitted($activityType))
		{
			return null;
		}

		if ($this->remainingConstructions <= 0)
		{
			return null;
		}
		$this->remainingConstructions--;

		return ContentBlockResolver::resolve($activityType, $properties, $this->context);
	}
}
