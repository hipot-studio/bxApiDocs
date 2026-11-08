<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Internal\Service\Activity\ActivityPropertyPassports;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\DocumentField\AccessValidationService;
use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Public\Service\Storage\StorageReference;
use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Fragment\Enum\SkipReason;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeClassifier;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * What lies inside the settings of a node the library hands over. The content of a fragment is composed by
 * one user and turned into an executable template by another, while nothing on the way validates the
 * settings: the autosave of a draft does not, and the full validation lives on the publication. The
 * applicability filter does not either - it answers what kind of node it is, never what it carries (ADR,
 * invariant 3), so the two checks stand side by side and neither covers the other.
 *
 * Three rules, in this order, and every one of them built out of the very means the same class of problem is
 * already closed with for the blocks of an external AI agent - no register of forbidden settings of our own:
 *   1. a restricted setting does not pass at all. On the path of the agent such a setting is admitted only as
 *      a verbatim repeat of the published baseline; a fragment has no baseline in the recipient by
 *      definition, so a node carrying a written value of one is skipped. An empty value carries no payload
 *      ({@see RestrictedSettingRegistry::carriesNoPayload()}) and the barrier does not touch it;
 *   2. a reference to storage is authorised by {@see StorageReferenceGuard::authorizeReference()} against the
 *      published baseline of the RECIPIENT, so a code the recipient never declared and an addressing of
 *      storage by id are refused by its rules and not by ours;
 *   3. a value of a field that declared a check of the access is asked of {@see AccessValidationService} on
 *      behalf of the user doing the insertion.
 *
 * A node failing any rule is skipped whole, with the reason of its own. It is neither cleaned nor inserted in
 * part: silently changing what an executable setting says is worse than leaving the node out.
 *
 * A nested node is judged by one more thing, and there the barrier does answer what kind of node it is: whether
 * the parent it stands under offers it at all ({@see self::isAdmissibleChild()}). The applicability filter asks
 * that at the top level only and never descends, so nothing else looks at a nested node - which is why the
 * whole depth of the Children is walked here.
 *
 * The barrier is fail-closed by contract - a setting it cannot prove safe is treated as forbidden - so a node
 * whose type is missing, whose settings are unreadable, or which the means above cannot be asked about, is
 * skipped rather than let through. The type of a node is brought to the spelling of the catalog before any of
 * the rules is asked ({@see self::canonicalTypeOf()}), because a rule comparing the spelling it was handed
 * against a list of canonical codes answers "not a storage node" to a storage node.
 */
final class SettingsBarrier
{
	private const SEARCHER_SERVICE = 'bizproc.runtime.activitysearcher.searcher';
	private const COMPLEX_ACTIVITY_SERVICE = 'bizproc.service.activity.complex';

	/** The module that owns every means the three rules are built out of. {@see self::reasonToSkip()} */
	private const OWNER_MODULE = 'bizprocdesigner';

	private ?RestrictedSettingRegistry $restrictedSettings;
	private ?StorageReferenceGuard $storageGuard;
	private ?AccessValidationService $accessValidation;
	private ?Searcher $searcher;
	private ?ComplexActivityService $complexActivity;

	/** The baseline of the recipient, once something has really asked for it. {@see self::storageContext()} */
	private ?StorageAuthorizationContext $storageContext = null;

	private ?\Closure $storageContextResolver = null;

	/** @var array<string, string|null> the class of the catalog per type as written, null for a type it has none for */
	private array $canonicalTypes = [];

	/** @var array<string, array<string, string>> declared type per lower-cased property, by node type and document */
	private array $propertyTypes = [];

	/** @var array<string, string|false|null> the class of a field type, false when it could not be asked */
	private array $typeClasses = [];

	/** @var array<string, bool> the verdict of the check of the access, by the field type, the user and the value */
	private array $accessVerdicts = [];

	/**
	 * The children the unified panel of a parent offers, by the parent and the document type. The walk of the
	 * catalog of the actions behind it is paid once per document type of the request.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $admissibleChildren = [];

	/**
	 * @param StorageAuthorizationContext|\Closure|null $storageContext the published baseline of the
	 *        RECIPIENT, either assembled or as a callable assembling it on demand. Assembling it reads the
	 *        published template of the recipient, converts it whole and fingerprints every block of it, while
	 *        rule 2 is the only reader of the result and only for a node that consumes storage - so a callable
	 *        is asked once at the first such node and never at all for a fragment that carries none.
	 *
	 *        Left out - and for a callable answering with nothing - it is the empty baseline: no bound template
	 *        and no declared code, under which the guard authorises no storage reference at all. That is the
	 *        honest default of an insertion - a fragment node is part of no published template of the
	 *        recipient - and a caller that can assemble the baseline of the recipient hands it in instead of
	 *        the barrier collecting one of its own. Being late is all the laziness changes: no baseline is a
	 *        refusal here exactly as it was before, and never a permission by absence.
	 */
	public function __construct(
		?RestrictedSettingRegistry $restrictedSettings = null,
		?StorageReferenceGuard $storageGuard = null,
		?AccessValidationService $accessValidation = null,
		StorageAuthorizationContext|\Closure|null $storageContext = null,
		?Searcher $searcher = null,
		?ComplexActivityService $complexActivity = null,
	)
	{
		$this->restrictedSettings = $restrictedSettings;
		$this->storageGuard = $storageGuard;
		$this->accessValidation = $accessValidation;
		$this->searcher = $searcher;
		$this->complexActivity = $complexActivity;

		if ($storageContext instanceof \Closure)
		{
			$this->storageContextResolver = $storageContext;
		}
		else
		{
			$this->storageContext = $storageContext ?? self::noBaseline();
		}
	}

	/**
	 * @param array $nodes activities that passed the applicability filter, with their original names
	 * @param array $links triples of the links between them
	 * @param array $targetDocumentType the document type of the recipient, never of the source
	 * @param int $userId the user doing the insertion
	 *
	 * @return array{nodes: array, links: array, skipped: list<array{nodeName: string, reason: SkipReason}>}
	 */
	public function apply(array $nodes, array $links, array $targetDocumentType, int $userId): array
	{
		$kept = [];
		$skipped = [];

		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$reason = $this->reasonToSkip($node, $targetDocumentType, $userId);
			if ($reason === null)
			{
				$kept[] = $node;

				continue;
			}

			$skipped[] = [
				'nodeName' => (string)($node[NodesToTemplate::ELEMENT_NAME] ?? ''),
				'reason' => $reason,
			];
		}

		return [
			'nodes' => $kept,
			'links' => FragmentGraph::keepLinksBetween($links, $kept),
			'skipped' => $skipped,
		];
	}

	/**
	 * Why the node may not travel, and null while it may.
	 *
	 * The rules run in the normative order and behind one gate of the module that owns the means they are
	 * built of. A payload inside a nested node of a complex node is the same payload, so a child that fails
	 * costs the node it stands in its place - a complex node travels whole or not at all. The reason of a
	 * child is the reason the outer node is skipped with, so what the user is told about a node they can see
	 * on the canvas is why it was really left out.
	 *
	 * The type is canonicalised once, before anything else is asked, and only the canonical one is passed on:
	 * every rule, and the white list of the parent, must judge the same activity the runtime will execute,
	 * whatever spelling the fragment carries. A type with no canonical form is refused as UNSUPPORTED - the
	 * reason belongs to the type not existing and not to what its settings hold.
	 *
	 * @param string|null $parentType canonical type of the node this one stands under, null at the top level
	 */
	private function reasonToSkip(
		array $node,
		array $documentType,
		int $userId,
		?string $parentType = null,
	): ?SkipReason
	{
		$type = $this->canonicalTypeOf((string)($node[NodesToTemplate::ELEMENT_TYPE] ?? ''));
		if ($type === null)
		{
			return SkipReason::Unsupported;
		}

		if ($parentType !== null && !$this->isAdmissibleChild($type, $parentType, $documentType))
		{
			return SkipReason::Unsupported;
		}

		$properties = $node[NodesToTemplate::ELEMENT_PROPERTIES] ?? [];
		if (!is_array($properties))
		{
			return SkipReason::RestrictedSettings;
		}

		// Every rule below is built out of the means of one other module, and two of them dereference its
		// classes outright. The module is required here explicitly, so that the fail-closed answer of the
		// barrier rests on this line and not on the accident of rule 1 standing first: a rule put before that
		// one would otherwise turn a refusal into a fatal error.
		if (!Loader::includeModule(self::OWNER_MODULE))
		{
			return SkipReason::RestrictedSettings;
		}

		if (
			!$this->passesRestrictedSettings($type, $properties, $documentType)
			|| !$this->passesStorageReference($type, $properties)
			|| !$this->passesValueAccess($type, $properties, $documentType, $userId)
		)
		{
			return SkipReason::RestrictedSettings;
		}

		$children = $node[NodesToTemplate::ELEMENT_CHILDREN] ?? [];
		if (!is_array($children))
		{
			return SkipReason::RestrictedSettings;
		}

		foreach ($children as $child)
		{
			if (!is_array($child))
			{
				return SkipReason::RestrictedSettings;
			}

			$reason = $this->reasonToSkip($child, $documentType, $userId, $type);
			if ($reason !== null)
			{
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Whether the unified panel of the parent offers this child at all (ALG-02, errata of 2026-09-09).
	 *
	 * Why the gate stands in a nested position: the applicability filter answers what kind of node it is for
	 * the top level and deliberately does not descend, so a nested node is looked at by nothing else, while the
	 * recipient does not even see it - only the activities of the top level become blocks of the canvas. A node
	 * that arrived this way would be published and executed without ever having been chosen, which is how the
	 * first half of invariant 3 of the ADR came to be false below the top level. It is asked of children only:
	 * at the top level the filter has already asked it, and asking it again would refuse a legitimate outer
	 * composite the palette does not declare.
	 *
	 * Why the answer is taken in the document type of the RECIPIENT, and from this source: the white list is
	 * the very source of truth the regular check of a child of a complex node reads
	 * ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity::validateUnifiedPanelChild()}), so the two
	 * cannot drift apart, and it is built from the catalog of the actions resolved in that document type - the
	 * tariff lock is computed inside that resolution and a locked node is out of the list rather than asked
	 * about afterwards. A lookup by a search carrying no document type, the way the canonicalisation above has
	 * to work, would answer with an empty lock for every node there is.
	 *
	 * Without the service nothing here can be proven and no child is admissible: an absent answer is a refusal.
	 */
	private function isAdmissibleChild(string $type, string $parentType, array $documentType): bool
	{
		$codes = $this->admissibleChildCodes($parentType, $documentType);

		return $codes !== [] && isset($codes[$this->searcher()->normalizeActivityCode($type)]);
	}

	/**
	 * @return array<string, true> normalized code of every admissible child, empty when it cannot be asked
	 */
	private function admissibleChildCodes(string $parentType, array $documentType): array
	{
		$key = $parentType . '|' . implode('|', $documentType);
		if (array_key_exists($key, $this->admissibleChildren))
		{
			return $this->admissibleChildren[$key];
		}

		$service = $this->complexActivity();

		return $this->admissibleChildren[$key] = $service === null
			? []
			: $service->getUnifiedPanelChildCodes($parentType, $documentType)
		;
	}

	/**
	 * Rule 1. The names are asked of the activity itself through the existing register, which memoizes them
	 * per type of node and document type, so a fragment of two hundred nodes loads the condition files once.
	 *
	 * Without the register nothing here can be proven, and the barrier answers the way it answers to
	 * everything it cannot prove - the module it lives in is required before any rule is asked at all.
	 */
	private function passesRestrictedSettings(string $type, array $properties, array $documentType): bool
	{
		$registry = $this->restrictedSettings();
		if ($registry === null)
		{
			return false;
		}

		$restrictedNames = $registry->namesFor($type, self::describeDocument($documentType));
		if ($restrictedNames === [])
		{
			return true;
		}

		foreach ($properties as $name => $value)
		{
			if (
				is_string($name)
				&& in_array(mb_strtolower($name), $restrictedNames, true)
				&& !RestrictedSettingRegistry::carriesNoPayload($value)
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Rule 2. A consumer of storage is handed to the guard as it is, and the verdict comes from the rules of
	 * the recipient: a code its published version never declared, and an addressing of storage by id, are
	 * refused there.
	 *
	 * A node that CREATES storage is not judged by the guard at all - it says so itself - and a reference it
	 * cannot judge is a reference the barrier cannot prove safe, so such a node never travels with a fragment.
	 *
	 * The setup wizard is asked by {@see StorageNodeClassifier::isStorageCreator()} and not by
	 * {@see StorageNodeClassifier::isImmutable()}, which holds both of them (errata of 2026-09-09). The
	 * taxonomy of the storage nodes is no answer about the wizard, and its own author says why: the wizard
	 * configures the template as a whole and touches no storage, so a rule about references to storage has
	 * nothing to refuse it for. What its form does carry - the declarations of the constants of the SOURCE
	 * template - is not a reference the recipient could authorise but a setting of the recipient to be filled
	 * in, and the decision of the developer is that it travels: an inserted wizard the person has to finish
	 * configuring is worth more than a node silently left out. Its settings are still judged by rules 1 and 3
	 * like any other node's.
	 *
	 * The fingerprint of the node is empty on purpose: it exists to recognise an unchanged node of the
	 * published template, and a node arriving from the library is part of no published template of the
	 * recipient.
	 */
	private function passesStorageReference(string $type, array $properties): bool
	{
		if (StorageNodeClassifier::isStorageCreator($type))
		{
			return false;
		}

		if (!StorageNodeClassifier::isConsumer($type))
		{
			return true;
		}

		$settings = self::toAgentSettings($properties);
		if ($settings === null)
		{
			return false;
		}

		$operationKind = StorageNodeClassifier::resolveOperationKind($type, $settings);
		if ($operationKind === null)
		{
			return false;
		}

		return $this->storageGuard()->authorizeReference(
			new StorageReference(
				storageCode: StorageNodeClassifier::extractStorageCode($settings),
				storageId: StorageNodeClassifier::extractStorageId($settings),
				fingerprint: '',
			),
			$operationKind,
			$this->storageContext(),
		)->allowed;
	}

	/**
	 * Rule 3. The check belongs to the field type, not to the barrier: a type declaring none answers that the
	 * access is granted, and only a type that declared one is really asked. The value is taken as it stands in
	 * the settings, so an expression left pointing at the source template resolves to nothing and passes -
	 * a reference out of the fragment is the business of the list of the places to review, not of the access.
	 */
	private function passesValueAccess(string $type, array $properties, array $documentType, int $userId): bool
	{
		$propertyTypes = $this->propertyTypesOf($type, $documentType);
		if ($propertyTypes === [])
		{
			return true;
		}

		foreach ($properties as $name => $value)
		{
			if (!is_string($name) || RestrictedSettingRegistry::carriesNoPayload($value))
			{
				continue;
			}

			$typeClass = $this->typeClassOf($propertyTypes[mb_strtolower($name)] ?? '', $documentType);
			if ($typeClass === null)
			{
				continue;
			}

			if ($typeClass === false || !$this->isValueAccessible($typeClass, $userId, $value))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * A verdict is remembered per field type, per user and per value: the check belongs to the field type, and
	 * for a type like the project one it reaches the provider of another module. A fragment repeats the same
	 * value across its nodes far more often than not, and without this the rule would be a request per node.
	 */
	private function isValueAccessible(string $typeClass, int $userId, mixed $value): bool
	{
		$key = $typeClass . '|' . $userId . '|' . hash('sha256', serialize($value));

		return $this->accessVerdicts[$key] ??= $this->accessValidation()
			->isUserHasAccessToValue($typeClass, $userId, $value)
			->isSuccess()
		;
	}

	/**
	 * The settings of a node in the shape the taxonomy of the storage nodes reads them. A value that is
	 * neither a string nor an array is carried as the guard itself reads it - through a cast to a string,
	 * exactly what {@see StorageReferenceGuard::addressesStorageById()} does - while an absent value and a
	 * null are one and the same thing to it. Anything else cannot be carried without changing what it says,
	 * and the barrier refuses the node instead of guessing.
	 */
	private static function toAgentSettings(array $properties): ?AgentSettingCollection
	{
		$settings = [];

		foreach ($properties as $name => $value)
		{
			if (!is_string($name) || $value === null)
			{
				continue;
			}

			if (is_string($value) || is_array($value))
			{
				$settings[] = new AgentSetting($name, $value);
			}
			elseif (is_scalar($value))
			{
				$settings[] = new AgentSetting($name, (string)$value);
			}
			else
			{
				return null;
			}
		}

		return new AgentSettingCollection($settings);
	}

	/**
	 * The class the catalog spells the type with, asked once per node and remembered per spelling. The runtime
	 * reads `CBPWriteDataStorageActivity` and `WriteDataStorageActivity` as one and the same activity
	 * ({@see Searcher::normalizeActivityCode()}), so a rule holding a list of canonical codes has to be handed
	 * the canonical one - anything weaker is not fail-closed, it simply answers about another type.
	 *
	 * The class is the form the applicability filter keys its own catalog by
	 * ({@see ApplicabilityFilter::descriptions()}), so the two halves of the same invariant now agree on what a
	 * type is. A type the catalog declares nothing for has no canonical form and the node is refused: at the
	 * top level such a node is already out on the filter, while inside a nested one the barrier is the only
	 * check there is.
	 */
	private function canonicalTypeOf(string $type): ?string
	{
		if ($type === '')
		{
			return null;
		}

		if (!array_key_exists($type, $this->canonicalTypes))
		{
			$class = (string)$this->searcher()->searchByCode($type)?->getClass();
			$this->canonicalTypes[$type] = $class === '' ? null : $class;
		}

		return $this->canonicalTypes[$type];
	}

	/**
	 * The types the activity declares for its own settings, read off the pair of maps every consumer of a
	 * passport reads ({@see ActivityPropertyPassports}). The `PROPERTIES` key of a descriptor is not that
	 * source: `.description.php` keeps something else entirely under it.
	 *
	 * @return array<string, string> the declared type of every property of the node, by its lower-cased name
	 */
	private function propertyTypesOf(string $type, array $documentType): array
	{
		$key = $type . '|' . implode('|', $documentType);

		return $this->propertyTypes[$key] ??= ActivityPropertyPassports::read(
			$type,
			$documentType,
			static function (mixed $map): array
			{
				$types = [];

				foreach (is_array($map) ? $map : [] as $name => $property)
				{
					$propertyType = is_array($property) ? ($property['Type'] ?? null) : null;
					if (is_string($name) && is_string($propertyType) && $propertyType !== '')
					{
						$types[mb_strtolower($name)] = $propertyType;
					}
				}

				return $types;
			},
		);
	}

	/**
	 * @return string|false|null the class of the field type, null when there is none to ask and false when the
	 *         document service could not be asked at all
	 */
	private function typeClassOf(string $propertyType, array $documentType): string|false|null
	{
		if ($propertyType === '')
		{
			return null;
		}

		// The document service answers per document type, so the type is part of the key: a memo is asked
		// nothing it did not answer, whatever the scope of the instance holding it happens to be today.
		$key = mb_strtolower($propertyType) . '|' . implode('|', $documentType);
		if (array_key_exists($key, $this->typeClasses))
		{
			return $this->typeClasses[$key];
		}

		try
		{
			$typeClass = \CBPRuntime::getRuntime()->getDocumentService()->getTypeClass($documentType, $propertyType);
		}
		catch (\Throwable)
		{
			return $this->typeClasses[$key] = false;
		}

		return $this->typeClasses[$key] = is_string($typeClass) && $typeClass !== '' ? $typeClass : null;
	}

	private static function describeDocument(array $documentType): DocumentDescription
	{
		return new DocumentDescription(
			(string)($documentType[0] ?? ''),
			(string)($documentType[1] ?? ''),
			(string)($documentType[2] ?? ''),
		);
	}

	private function restrictedSettings(): ?RestrictedSettingRegistry
	{
		if ($this->restrictedSettings === null && Loader::includeModule(self::OWNER_MODULE))
		{
			$this->restrictedSettings = new RestrictedSettingRegistry();
		}

		return $this->restrictedSettings;
	}

	private function storageGuard(): StorageReferenceGuard
	{
		return $this->storageGuard ??= new StorageReferenceGuard();
	}

	/**
	 * The baseline, assembled at the latest possible moment and kept: the second node consuming storage is
	 * judged against the very same one as the first.
	 *
	 * A resolver answering with nothing is the empty baseline and not an open door - the guard refuses every
	 * reference against it - while a resolver that fails is left to fail: an error is not a verdict, and
	 * turning one into "no baseline" would let a broken read of the recipient decide what is allowed.
	 */
	private function storageContext(): StorageAuthorizationContext
	{
		if ($this->storageContext === null)
		{
			$resolver = $this->storageContextResolver;
			$this->storageContext = ($resolver === null ? null : $resolver()) ?? self::noBaseline();
		}

		return $this->storageContext;
	}

	private static function noBaseline(): StorageAuthorizationContext
	{
		return new StorageAuthorizationContext(0, []);
	}

	private function accessValidation(): AccessValidationService
	{
		return $this->accessValidation ??= new AccessValidationService();
	}

	private function searcher(): Searcher
	{
		return $this->searcher ??= ServiceLocator::getInstance()->get(self::SEARCHER_SERVICE);
	}

	/**
	 * The instance of the request and never one of the barrier's own: the walk of the catalog of the actions
	 * behind the white list is memoized inside the service per document type, so every surface of the request
	 * that asks for it shares the one walk.
	 */
	private function complexActivity(): ?ComplexActivityService
	{
		if ($this->complexActivity === null && ServiceLocator::getInstance()->has(self::COMPLEX_ACTIVITY_SERVICE))
		{
			$this->complexActivity = ServiceLocator::getInstance()->get(self::COMPLEX_ACTIVITY_SERVICE);
		}

		return $this->complexActivity;
	}
}
