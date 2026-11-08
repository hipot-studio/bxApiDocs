<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Ads\Internals\AdsFormLinkTable;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\V2\Internal\Integration\Sale\PaySystemGateway;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormReferenceRepository;
use Bitrix\Crm\WebForm\Callback;
use Bitrix\Crm\WebForm\Entity;
use Bitrix\Crm\WebForm\EntityFieldProvider;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Manager;
use Bitrix\Crm\WebForm\Options\Dictionary;
use Bitrix\Crm\WebForm\ResultEntity;
use Bitrix\Crm\WebForm\WhatsApp;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Checks a change set of a CRM form against the CRM the form lives in.
 *
 * Everything the storage can not answer for belongs here: the references of the input have to point
 * at something that exists (the agreements, the users of the queue, the CRM fields), the rules of
 * the composition have to hold (a dependency rule points only at a field the form ends up with), the
 * limits the storage declares but never applies have to be kept (the length of the name), and the
 * addresses the public form redirects to have to carry a scheme a browser may be sent to.
 *
 * The tables the references live in are not read from here: the users of the queue, the agreements
 * and the funnels of the acting user arrive through the closures of the constructor, which default
 * to FormReferenceRepository, the way the write scenario takes its storage. The pay systems of the
 * portal arrive the same way, from the integration gateway of the sale module. The rest of what the
 * check asks about - the schemes, the CRM fields, the dictionary of the dependencies, the field
 * types - comes from the providers of the module and stays a direct call.
 *
 * Every violation is collected instead of raising the first one, so a caller learns about all of
 * them at once. The check answers with a result: an unsuccessful one carries an error per
 * violation, and the code of every error is the path of the input the violation belongs to.
 * Turning that into the exception of the layer is the business of the service.
 *
 * Not final: the write scenarios take the validator through the constructor and a test replaces it.
 *
 * @internal
 */
class WebFormValidator
{
	/**
	 * Input paths of the values the checks reach for. Public because the service builds the
	 * projection of the stored form out of them and binds the refusals of the storage to them.
	 */
	public const ENTITY_SCHEME_PATH = WebFormPath::ENTITY_SCHEME;
	public const DUPLICATE_MODE_PATH = WebFormPath::DUPLICATE_MODE;
	public const REDIRECT_DELAY_PATH = WebFormPath::REDIRECT_DELAY;
	public const REFILL_CAPTION_PATH = WebFormPath::REFILL_CAPTION;
	public const REFILL_ENABLED_PATH = WebFormPath::REFILL_ENABLED;
	public const DEAL_CATEGORY_ID_PATH = WebFormPath::DEAL_CATEGORY_ID;
	public const SMART_PROCESS_CATEGORY_ID_PATH = WebFormPath::SMART_PROCESS_CATEGORY_ID;
	public const RESPONSIBLE_USER_IDS_PATH = WebFormPath::RESPONSIBLE_USER_IDS;
	public const CHECK_WORK_TIME_PATH = WebFormPath::RESPONSIBLE_CHECK_WORK_TIME;
	public const AGREEMENT_ITEMS_PATH = WebFormPath::AGREEMENT_ITEMS;
	public const ADS_PATH = WebFormPath::ADS;
	public const PAYMENT_ENABLED_PATH = WebFormPath::PAYMENT_ENABLED;
	public const DISABLED_PAY_SYSTEMS_ID_PATH = WebFormPath::DISABLED_PAY_SYSTEMS_ID;
	public const CALLBACK_ENABLED_PATH = WebFormPath::CALLBACK_ENABLED;
	public const CALLBACK_FROM_PATH = WebFormPath::CALLBACK_FROM;
	public const WHATS_APP_ENABLED_PATH = WebFormPath::WHATS_APP_ENABLED;

	/**
	 * Where the visitor of the public form is sent after a submission. Both addresses end up in the
	 * location of the page the form is embedded in, so the schemes they accept are limited here.
	 */
	private const REDIRECT_URL_PATHS = [
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_SUCCESS
			. '.' . Section\SubmissionResult::FIELD_URL,
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_FAILURE
			. '.' . Section\SubmissionResult::FIELD_URL,
	];

	/**
	 * Length of the form name the storage declares but does not apply: the validator of the column
	 * is written and never attached, so the limit only exists in this contract.
	 */
	private const NAME_MAX_LENGTH = 50;

	/**
	 * Length of the caption of the offer to fill the form again. The sub keys of the settings column
	 * are bounded by nothing at all, while every caption of a form in the very same table is a
	 * `varchar(255)` - `BUTTON_CAPTION`, `CAPTION`, `LICENCE_BUTTON_CAPTION` - and this is the caption
	 * of a button just like those. Unbounded, one caption fills the column the whole tree of the
	 * settings shares and takes the design and the embedding variants of the form down with it.
	 */
	private const REFILL_CAPTION_MAX_LENGTH = 255;

	/**
	 * Seconds the redirect of a successful submission waits. The storage bounds nothing here, while
	 * both editors of the form offer this very range, so a value outside it would be stored as a
	 * form neither of them can show.
	 */
	private const REDIRECT_DELAY_MIN_SECONDS = 0;
	private const REDIRECT_DELAY_MAX_SECONDS = 10;

	/** Field types building the layout of the form instead of carrying a CRM field. */
	private const LAYOUT_FIELD_TYPES = [
		FieldTable::TYPE_ENUM_BR,
		FieldTable::TYPE_ENUM_HR,
		FieldTable::TYPE_ENUM_SECTION,
		FieldTable::TYPE_ENUM_PAGE,
	];

	/** Operation the legacy dependency converter accepts on top of the ones of the dictionary. */
	private const EXTRA_DEPENDENCY_OPERATION = '<>';

	private readonly \Closure $suitableUserIdsLoader;
	private readonly \Closure $usableAgreementIdsLoader;
	private readonly \Closure $availableCategoryIdsLoader;
	private readonly \Closure $activePaySystemIdsLoader;

	/**
	 * @param null|\Closure(int[]): int[] $suitableUserIdsLoader answers which of the given users may
	 *        be responsible for a form
	 * @param null|\Closure(int[]): int[] $usableAgreementIdsLoader answers which of the given
	 *        agreements may be bound to a form
	 * @param null|\Closure(int, int): int[] $availableCategoryIdsLoader answers which funnels of the
	 *        given CRM entity type the given user may work in
	 * @param null|\Closure(): ?int[] $activePaySystemIdsLoader answers which pay systems the portal
	 *        has, and null when there is no way to ask - the sale module is not installed
	 */
	public function __construct(
		?\Closure $suitableUserIdsLoader = null,
		?\Closure $usableAgreementIdsLoader = null,
		?\Closure $availableCategoryIdsLoader = null,
		?\Closure $activePaySystemIdsLoader = null,
	)
	{
		$this->suitableUserIdsLoader = $suitableUserIdsLoader ?? static fn (array $ids): array =>
			(new FormReferenceRepository())->getSuitableUserIds($ids)
		;
		$this->usableAgreementIdsLoader = $usableAgreementIdsLoader ?? static fn (array $ids): array =>
			(new FormReferenceRepository())->getUsableAgreementIds($ids)
		;
		$this->availableCategoryIdsLoader = $availableCategoryIdsLoader
			?? static fn (int $entityTypeId, int $userId): array =>
				(new FormReferenceRepository())->getAvailableCategoryIds($entityTypeId, $userId)
		;
		$this->activePaySystemIdsLoader = $activePaySystemIdsLoader ?? static fn (): ?array =>
			(new PaySystemGateway())->getActivePaySystemIds()
		;
	}

	/**
	 * Checks the change set against the CRM. A stored form is the state the partial change set is
	 * read on top of; null means the form is being created.
	 *
	 * The user is the one acting: what the CRM allows depends on who is asking, and this contract
	 * takes that from its caller instead of reading it off the context of the request.
	 *
	 * @return Result unsuccessful when the input is not valid; every error carries the input path
	 *         as its code
	 */
	public function validate(WebFormChangeSet $changeSet, ?WebForm $storedForm, int $userId): Result
	{
		$result = new Result();

		self::validateName($changeSet, $storedForm, $result);
		self::validateRedirectUrls($changeSet, $result);
		self::validateRedirectDelay($changeSet, $result);
		self::validateRefillCaption($changeSet, $storedForm, $result);
		self::validateRefillPair($changeSet, $storedForm, $result);
		self::validateDuplicateMode($changeSet, $result);
		self::validateGatedFeatures($changeSet, $storedForm, $result);
		$this->validateDisabledPaySystems($changeSet, $storedForm, $result);
		$schemeId = self::resolveSchemeId($changeSet, $storedForm, $result);
		$this->validateDealCategory($changeSet, $storedForm, $schemeId, $userId, $result);
		$this->validateSmartProcessCategory($changeSet, $storedForm, $schemeId, $userId, $result);
		$storedTypes = self::toStoredFieldTypes($storedForm?->fields);
		$fieldNames = $changeSet->has(WebForm::SECTION_FIELDS)
			? self::toFieldNames($changeSet->get(WebForm::SECTION_FIELDS))
			: self::toFieldNames($storedForm?->fields)
		;
		// Building the universe of CRM fields costs hundreds of milliseconds, and only the checks
		// below read it: a change set carrying none of these never needs it. A change of the scheme
		// needs it too - what the form already holds has to fit the scheme it is moved to.
		$needsCrmFields = $changeSet->has(WebForm::SECTION_FIELDS)
			|| $changeSet->has(WebForm::SECTION_PRESET_FIELDS)
			|| $changeSet->has(self::ENTITY_SCHEME_PATH)
		;
		$crmFields = ($schemeId === null || !$needsCrmFields) ? null : self::loadCrmFields($schemeId);

		self::validateSchemeChange($changeSet, $storedForm, $storedTypes, $crmFields, $result);
		self::validateFields($changeSet, $storedTypes, $schemeId, $crmFields, $result);
		self::validateDependencies($changeSet, $fieldNames, $result);
		self::validatePresetFields($changeSet, $crmFields, $result);
		$this->validateResponsible($changeSet, $result);
		self::validateCheckWorkTime($changeSet, $storedForm, $result);
		$this->validateAgreements($changeSet, $storedForm, $result);
		self::validateAdIntegrations($changeSet, $storedForm, $fieldNames, $result);
		if ($result->isSuccess())
		{
			self::validateAdProviders($changeSet, $result);
		}

		return $result;
	}

	private static function validateName(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$path = WebForm::FIELD_NAME;
		if (!$changeSet->has($path))
		{
			if ($storedForm === null)
			{
				$result->addError(new Error('Name of the CRM form is required', $path));
			}

			return;
		}

		$name = (string)$changeSet->get($path);
		if ($name === '')
		{
			$result->addError(new Error('Name of the CRM form is required', $path));

			return;
		}

		if (mb_strlen($name) > self::NAME_MAX_LENGTH)
		{
			$result->addError(new Error(
				sprintf('Name of the CRM form is longer than %d characters', self::NAME_MAX_LENGTH),
				$path,
			));
		}
	}

	/**
	 * Redirect addresses accept a relative path and an http(s) address, nothing else. The public
	 * runtime of the form assigns the stored address to the location of the page the form is
	 * embedded in, so a `javascript:` address would run in the origin of that page. An empty
	 * string and null stay valid: that is how the address is cleared.
	 *
	 * The scheme is read without regard to case, the way a browser reads it: `HTTPS://` names the
	 * same scheme as `https://` and refusing it would be a refusal of a valid address.
	 */
	private static function validateRedirectUrls(WebFormChangeSet $changeSet, Result $result): void
	{
		foreach (self::REDIRECT_URL_PATHS as $path)
		{
			if (!$changeSet->has($path))
			{
				continue;
			}

			$url = (string)$changeSet->get($path);
			if ($url === '' || preg_match('#^(?:/|https?://)#i', $url) === 1)
			{
				continue;
			}

			$result->addError(new Error(
				'Redirect address of the form accepts a relative path or an http address only',
				$path,
			));
		}
	}

	/**
	 * How long the redirect of a successful submission waits: a whole number of seconds inside the
	 * range both editors of the form offer. The check is a domain one rather than a transport one -
	 * the transport reads the type of the value alone, and the address of a refusal has to be the
	 * same path whichever stage refused it.
	 *
	 * Null passes: it clears the setting, and a cleared one is stored as the very value the read
	 * answers for a form carrying none.
	 */
	private static function validateRedirectDelay(WebFormChangeSet $changeSet, Result $result): void
	{
		$path = self::REDIRECT_DELAY_PATH;
		if (!$changeSet->has($path))
		{
			return;
		}

		$delay = $changeSet->get($path);
		if ($delay === null)
		{
			return;
		}

		// A number written as a string is a number, the way the transport reads one.
		$isWholeNumber = is_int($delay) || (is_string($delay) && preg_match('/^-?\d+$/D', $delay) === 1);
		$seconds = (int)$delay;
		if (
			$isWholeNumber
			&& $seconds >= self::REDIRECT_DELAY_MIN_SECONDS
			&& $seconds <= self::REDIRECT_DELAY_MAX_SECONDS
		)
		{
			return;
		}

		$result->addError(new Error(
			sprintf(
				'Delay before the redirect of the form is a whole number of seconds from %d to %d',
				self::REDIRECT_DELAY_MIN_SECONDS,
				self::REDIRECT_DELAY_MAX_SECONDS,
			),
			$path,
		));
	}

	/**
	 * The caption of the offer to fill the form again is bounded in length. It lives in the settings
	 * column, which the whole tree of the settings of the form shares, so a caption nothing bounds
	 * is a way to lose every neighbouring setting at once rather than a long caption.
	 *
	 * The caption the form already holds passes whatever its length: the builder writing it bounds
	 * nothing, and the cycle of reading a form and sending it back has to go through (AC-021). Null
	 * and an empty string pass too - that is how the caption is cleared.
	 */
	private static function validateRefillCaption(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$path = self::REFILL_CAPTION_PATH;
		if (!$changeSet->has($path))
		{
			return;
		}

		$caption = (string)$changeSet->get($path);
		if (
			mb_strlen($caption) <= self::REFILL_CAPTION_MAX_LENGTH
			|| $caption === (string)$storedForm?->submission?->refill?->caption
		)
		{
			return;
		}

		$result->addError(new Error(
			sprintf(
				'Caption of the offer to fill the form again is longer than %d characters',
				self::REFILL_CAPTION_MAX_LENGTH,
			),
			$path,
		));
	}

	/**
	 * How the form treats a submission matching an entity the CRM already holds. The storage keeps a
	 * closed set of modes and refuses everything else by the column, which would answer the client
	 * with the name of that column instead of the path of the input; the empty mode and null are the
	 * two shapes of "no duplicate control", the way the read projection publishes them.
	 */
	/**
	 * The offer to fill the form again and its caption are stored apart from each other, and the
	 * public form draws the button of that offer by the caption alone: a form carrying the offer
	 * switched on and no caption answers the visitor with a state that has no way out of it. So the
	 * two are checked as one pair, over what the form holds after the change set is applied to it -
	 * switching the offer on without a caption and clearing the caption of an offer already on are
	 * the same outcome and are refused the same way.
	 *
	 * The pair the form already holds passes: it was stored before this check existed, and the cycle
	 * of reading a form and sending it back has to go through (AC-021). The refusal is addressed to
	 * the caption - that is the value the form is missing.
	 */
	private static function validateRefillPair(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		if (!$changeSet->has(self::REFILL_ENABLED_PATH) && !$changeSet->has(self::REFILL_CAPTION_PATH))
		{
			return;
		}

		$storedRefill = $storedForm?->submission?->refill;
		$enabled = $changeSet->has(self::REFILL_ENABLED_PATH)
			? (bool)$changeSet->get(self::REFILL_ENABLED_PATH)
			: (bool)$storedRefill?->enabled
		;
		$caption = $changeSet->has(self::REFILL_CAPTION_PATH)
			? (string)$changeSet->get(self::REFILL_CAPTION_PATH)
			: (string)$storedRefill?->caption
		;
		if (!$enabled || $caption !== '')
		{
			return;
		}

		if ((bool)$storedRefill?->enabled && (string)$storedRefill?->caption === '')
		{
			return;
		}

		$result->addError(new Error(
			'Offer to fill the form again is shown by its caption, so it can not be switched on without one',
			self::REFILL_CAPTION_PATH,
		));
	}

	private static function validateDuplicateMode(WebFormChangeSet $changeSet, Result $result): void
	{
		$path = self::DUPLICATE_MODE_PATH;
		if (!$changeSet->has($path))
		{
			return;
		}

		$mode = $changeSet->get($path);
		if ($mode === null || in_array((string)$mode, ResultEntity::getDuplicateModes(), true))
		{
			return;
		}

		$namedModes = array_filter(
			ResultEntity::getDuplicateModes(),
			static fn (string $duplicateMode): bool => $duplicateMode !== '',
		);

		$result->addError(new Error(
			sprintf(
				'Duplicate control mode of the form is one of `%s`, or empty to leave duplicates alone',
				implode('`, `', $namedModes),
			),
			$path,
		));
	}

	/**
	 * A form moved to another CRM scheme has to fit it whole.
	 *
	 * The scheme decides which CRM fields a form may carry, and the fields and the preset values it
	 * already holds are not re-read by a request that carries the scheme alone. Left unchecked, the
	 * move leaves a form whose stored fields belong to entities the new scheme does not have: the
	 * form keeps rendering them and a submission writes them nowhere. The same document sent with the
	 * sections together would be refused, so the difference is only in what the client happened to
	 * send.
	 *
	 * Refused by the path of the scheme: it is the value that does not fit, and the way through is to
	 * send the sections along with it.
	 *
	 * @param array<string, string|null> $storedTypes
	 * @param null|array{codes: array<string, string>, entityFields: array<string, array<string, true>>} $crmFields
	 */
	private static function validateSchemeChange(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		array $storedTypes,
		?array $crmFields,
		Result $result,
	): void
	{
		$path = self::ENTITY_SCHEME_PATH;
		if ($storedForm === null || !$changeSet->has($path) || $crmFields === null)
		{
			return;
		}

		if ((int)$changeSet->get($path) === (int)($storedForm->crm?->entityScheme ?? 0))
		{
			return;
		}

		if (!$changeSet->has(WebForm::SECTION_FIELDS))
		{
			foreach ($storedTypes as $name => $type)
			{
				if (in_array($type, self::LAYOUT_FIELD_TYPES, true) || isset($crmFields['codes'][$name]))
				{
					continue;
				}

				$result->addError(new Error(
					sprintf(
						'CRM field `%s` the form holds is not part of this scheme: send the fields of the form along with the scheme',
						$name,
					),
					$path,
				));
			}
		}

		if (!$changeSet->has(WebForm::SECTION_PRESET_FIELDS))
		{
			foreach ($storedForm->presetFields ?? [] as $presetField)
			{
				$entityName = (string)$presetField->entityName;
				$fieldName = (string)$presetField->fieldName;
				if (isset($crmFields['entityFields'][$entityName][$fieldName]))
				{
					continue;
				}

				$result->addError(new Error(
					sprintf(
						'Preset value of the CRM field `%s` the form holds is not part of this scheme: send the preset values along with the scheme',
						$fieldName,
					),
					$path,
				));
			}
		}
	}

	/**
	 * Switches the editor only offers where the portal can back them.
	 *
	 * Payment needs the orders of the portal, the callback needs telephony, and the messenger form is
	 * a feature of its own; the editor reads exactly these three answers and hides the switch when
	 * the answer is no. Written through this contract without asking, the switch would be stored on a
	 * form that can not act on it - a payment form on a portal that takes no orders, a callback form
	 * with nothing to call from.
	 *
	 * Only switching a feature on is refused, and only when the form does not already have it on:
	 * turning it off always passes, and a client reading a form that was switched on earlier and
	 * sending it back is not refused for what it did not do.
	 */
	private static function validateGatedFeatures(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$gates = [
			self::PAYMENT_ENABLED_PATH => [
				$storedForm?->payment?->enabled,
				static fn (): bool => Manager::isOrdersAvailable(),
				'Payment is not available on this portal',
			],
			self::CALLBACK_ENABLED_PATH => [
				$storedForm?->callback?->enabled,
				static fn (): bool => Callback::canUse(),
				'Callback is not available on this portal',
			],
			self::WHATS_APP_ENABLED_PATH => [
				$storedForm?->integrations?->whatsAppEnabled,
				static fn (): bool => WhatsApp::canUse(),
				'Messenger forms are not available on this portal',
			],
		];

		foreach ($gates as $path => [$storedValue, $isAvailable, $message])
		{
			if (!$changeSet->has($path) || !(bool)$changeSet->get($path))
			{
				continue;
			}

			if ($storedValue === true || $isAvailable())
			{
				continue;
			}

			$result->addError(new Error($message, $path));
		}

		self::validateCallbackNumber($changeSet, $storedForm, $result);
	}

	/**
	 * The number a callback is made from is one of the numbers the portal offers, the way the editor
	 * offers them. A number the form already holds passes: it was accepted when it was stored, and
	 * the portal losing it later is not this request talking.
	 */
	private static function validateCallbackNumber(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$path = self::CALLBACK_FROM_PATH;
		if (!$changeSet->has($path))
		{
			return;
		}

		$number = (string)$changeSet->get($path);
		if ($number === '' || $number === (string)$storedForm?->callback?->from)
		{
			return;
		}

		$offered = array_column(Callback::getPhoneNumbers(), 'CODE');
		if (in_array($number, $offered, true))
		{
			return;
		}

		$result->addError(new Error('This number can not be called from on this portal', $path));
	}

	/**
	 * Every pay system the form switches off is one the portal has, and the refusal is addressed by
	 * the element of the list rather than by the list: a client sending five identifiers learns
	 * which one of them is not there.
	 *
	 * The set is the one the snapshot of the settings publishes, asked through the same gateway - a
	 * second set would mean refusing a client the value the snapshot has just offered it. An
	 * identifier the form already holds passes whatever the portal says about it now: a pay system
	 * deleted after it was switched off is not this request talking, and the cycle of reading a form
	 * and sending it back has to go through (AC-021).
	 *
	 * A portal without the sale module has no way to answer, and then nothing is checked rather than
	 * everything refused. The list is checked whether the payment of the form is switched on or off:
	 * the submission of a form taking no payment never reads it.
	 *
	 * The set is read once and never per element: it costs a query, and a list of identifiers is
	 * checked against a map instead of asking about each of them.
	 */
	private function validateDisabledPaySystems(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$path = self::DISABLED_PAY_SYSTEMS_ID_PATH;
		if (!$changeSet->has($path))
		{
			return;
		}

		/** @var int[] $paySystemIds */
		$paySystemIds = $changeSet->get($path);
		if ($paySystemIds === [])
		{
			return;
		}

		$activeIds = ($this->activePaySystemIdsLoader)();
		if ($activeIds === null)
		{
			return;
		}

		$activeIdMap = self::toIdMap($activeIds);
		$storedIdMap = self::toIdMap($storedForm?->payment?->disabledPaySystemsId ?? []);

		foreach ($paySystemIds as $index => $paySystemId)
		{
			if (isset($activeIdMap[$paySystemId]) || isset($storedIdMap[$paySystemId]))
			{
				continue;
			}

			$result->addError(new Error(
				'This pay system can not be switched off in the form',
				WebFormPath::item($path, $index),
			));
		}
	}

	/**
	 * The funnel a submission creates its deal in is one of the funnels the acting user works in, and
	 * the scheme of the form has to create a deal at all.
	 *
	 * The set of funnels is the one the snapshot of the settings offers the same user, asked through
	 * the same rule: refusing a value the client has just been offered would be a contract arguing
	 * with itself.
	 *
	 * A scheme creating no deal is refused by the path of the funnel rather than by the path of the
	 * scheme, unlike {@see self::validateSchemeChange()}: there the stored data does not fit the new
	 * scheme and the way through is to send the sections along, while here it is the sent funnel that
	 * does not fit, and the way through is to leave it out or to send a scheme that creates a deal.
	 * The other way round - a scheme sent while the funnel stays stored - passes without a word: the
	 * submission of such a form never reads the stored funnel.
	 *
	 * Zero is a funnel of its own here - the main one - so the rule of a positive identifier does not
	 * apply.
	 */
	private function validateDealCategory(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		?int $schemeId,
		int $userId,
		Result $result,
	): void
	{
		$path = self::DEAL_CATEGORY_ID_PATH;
		$categoryId = self::resolveChangedCategoryId(
			$changeSet,
			$path,
			$storedForm?->crm?->dealCategoryId,
		);
		if ($categoryId === null || $schemeId === null)
		{
			return;
		}

		if (!Entity::isSchemeSupportEntity($schemeId, \CCrmOwnerType::Deal))
		{
			$result->addError(new Error(
				'Funnel of deals belongs to a CRM scheme creating a deal, and this one does not',
				$path,
			));

			return;
		}

		if (!$this->isCategoryAvailable(\CCrmOwnerType::Deal, $categoryId, $userId))
		{
			$result->addError(new Error('This funnel of deals can not be used in the form', $path));
		}
	}

	/**
	 * The funnel a submission creates its smart process item in: a funnel of the very entity type the
	 * scheme of the form names, and one the acting user works in. The scheme decides the type, so
	 * nothing else has to be sent to tell which funnels are meant.
	 *
	 * Zero means no funnel at all here, the way a submission of the form reads it, so it passes like
	 * null does. The rest follows {@see self::validateDealCategory()}.
	 */
	private function validateSmartProcessCategory(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		?int $schemeId,
		int $userId,
		Result $result,
	): void
	{
		$path = self::SMART_PROCESS_CATEGORY_ID_PATH;
		$categoryId = self::resolveChangedCategoryId(
			$changeSet,
			$path,
			$storedForm?->crm?->smartProcessCategoryId,
		);
		if ($categoryId === null || $categoryId === 0 || $schemeId === null)
		{
			return;
		}

		$entityTypeId = self::resolveSmartProcessEntityTypeId($schemeId);
		if ($entityTypeId === null)
		{
			$result->addError(new Error(
				'Funnel of a smart process belongs to a CRM scheme creating one, and this one does not',
				$path,
			));

			return;
		}

		if (!$this->isCategoryAvailable($entityTypeId, $categoryId, $userId))
		{
			$result->addError(new Error(
				'This funnel of the smart process can not be used in the form',
				$path,
			));
		}
	}

	/**
	 * The funnel the request changes, or null when there is nothing to check: the path is not sent,
	 * it clears the funnel, or it carries the funnel the form already holds.
	 *
	 * A stored funnel passes whatever the CRM says about it now - it was accepted when it was stored,
	 * and the portal or the rights of the user losing it later is not this request talking.
	 */
	private static function resolveChangedCategoryId(
		WebFormChangeSet $changeSet,
		string $path,
		?int $storedCategoryId,
	): ?int
	{
		if (!$changeSet->has($path))
		{
			return null;
		}

		$categoryId = $changeSet->get($path);
		if ($categoryId === null)
		{
			return null;
		}

		$categoryId = (int)$categoryId;

		return $categoryId === $storedCategoryId ? null : $categoryId;
	}

	/**
	 * The smart process a scheme creates items of, or null when the scheme creates none. The scheme
	 * of a smart process names its entity type itself, so the type is read off the provider instead
	 * of being computed out of the identifier of the scheme.
	 */
	private static function resolveSmartProcessEntityTypeId(int $schemeId): ?int
	{
		$scheme = Entity::getSchemes($schemeId);
		if (!is_array($scheme) || !(bool)($scheme['DYNAMIC'] ?? false))
		{
			return null;
		}

		$entityTypeId = (int)($scheme['MAIN_ENTITY'] ?? 0);

		return $entityTypeId > 0 ? $entityTypeId : null;
	}

	/**
	 * Whether the user works in this funnel of the CRM entity type. One wording answers both a funnel
	 * that is not there and a funnel the user may not read: told apart, they would tell an
	 * application holding the crm scope alone which funnels the portal has.
	 */
	private function isCategoryAvailable(int $entityTypeId, int $categoryId, int $userId): bool
	{
		$availableIds = ($this->availableCategoryIdsLoader)($entityTypeId, $userId);

		return isset(self::toIdMap($availableIds)[$categoryId]);
	}

	/**
	 * The CRM scheme the form works with: the one the change set carries, otherwise the stored one.
	 * Null means the scheme is unknown and every check depending on it is skipped.
	 */
	private static function resolveSchemeId(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): ?int
	{
		$path = self::ENTITY_SCHEME_PATH;
		$schemeId = $changeSet->has($path)
			? (int)$changeSet->get($path)
			: (int)($storedForm?->crm?->entityScheme ?? 0)
		;

		if ($schemeId <= 0)
		{
			$result->addError(new Error('CRM scheme of the form is required', $path));

			return null;
		}

		if (!Entity::getSchemes($schemeId))
		{
			$result->addError(new Error('CRM scheme of the form can not be used', $path));

			return null;
		}

		return $schemeId;
	}

	/**
	 * Type of the stored row every field name is kept by.
	 *
	 * A form can hold one name more than once - the storage has no unique key over the code - and a
	 * write keeps the first of those rows and removes the rest, so the first one is what a check
	 * about a stored field has to look at. The projection of the stored form arrives in the order of
	 * the storage, so the first occurrence here is that row.
	 *
	 * @param null|Section\Field[] $fields
	 * @return array<string, string|null>
	 */
	private static function toStoredFieldTypes(?array $fields): array
	{
		$types = [];
		foreach ($fields ?? [] as $field)
		{
			$name = (string)$field->name;
			if ($name !== '' && !array_key_exists($name, $types))
			{
				$types[$name] = $field->type;
			}
		}

		return $types;
	}

	/**
	 * @param null|Section\Field[] $fields
	 * @return array<string, true>
	 */
	private static function toFieldNames(?array $fields): array
	{
		$names = [];
		foreach ($fields ?? [] as $field)
		{
			$name = (string)$field->name;
			if ($name !== '')
			{
				$names[$name] = true;
			}
		}

		return $names;
	}

	/**
	 * The CRM fields a form of this scheme may use, keyed by the field code the form refers to them
	 * by. Only the entities of the scheme are asked about: reading the whole CRM instead costs
	 * seconds on a portal with many smart processes.
	 *
	 * @return array{codes: array<string, string>, entityFields: array<string, array<string, true>>}
	 */
	private static function loadCrmFields(int $schemeId): array
	{
		$scheme = Entity::getSchemes($schemeId);
		$schemeEntities = $scheme['ENTITIES'] ?? [];
		if ($schemeEntities === [])
		{
			// An empty list of entities would ask the provider about the whole CRM, which is the
			// read this method exists to avoid.
			return ['codes' => [], 'entityFields' => []];
		}

		$tree = EntityFieldProvider::getFieldsTree([], null, $schemeEntities);

		$codes = [];
		$entityFields = [];
		foreach ($tree as $entityName => $entity)
		{
			foreach ($entity['FIELDS'] ?? [] as $field)
			{
				$codes[(string)($field['name'] ?? '')] = (string)$entityName;
				$entityFields[(string)$entityName][(string)($field['entity_field_name'] ?? '')] = true;
			}
		}

		return ['codes' => $codes, 'entityFields' => $entityFields];
	}

	/**
	 * @param array<string, string|null> $storedTypes type of the row every stored name is kept by
	 * @param null|array{codes: array<string, string>, entityFields: array<string, array<string, true>>} $crmFields
	 */
	private static function validateFields(
		WebFormChangeSet $changeSet,
		array $storedTypes,
		?int $schemeId,
		?array $crmFields,
		Result $result,
	): void
	{
		if (!$changeSet->has(WebForm::SECTION_FIELDS))
		{
			return;
		}

		/** @var Section\Field[] $fields */
		$fields = $changeSet->get(WebForm::SECTION_FIELDS);
		$knownTypes = FieldTable::getTypeList();

		$seenNames = [];
		foreach ($fields as $index => $field)
		{
			$path = WebFormPath::item(WebForm::SECTION_FIELDS, $index);
			$name = (string)$field->name;
			if ($name === '')
			{
				$result->addError(new Error(
					'Name of the form field is required',
					WebFormPath::property($path, Section\Field::FIELD_NAME),
				));

				continue;
			}

			if (isset($seenNames[$name]))
			{
				// A field is identified by its name, so a repeated name would quietly collapse two
				// entries of the input into one row.
				$result->addError(new Error(
					sprintf('Form field `%s` is specified more than once', $name),
					WebFormPath::property($path, Section\Field::FIELD_NAME),
				));

				continue;
			}

			$seenNames[$name] = true;
			$isNew = !array_key_exists($name, $storedTypes);
			self::validateFieldType($field, $path, $isNew, $storedTypes[$name] ?? null, $knownTypes, $result);

			if ($crmFields === null || in_array($field->type, self::LAYOUT_FIELD_TYPES, true))
			{
				continue;
			}

			$entityName = $crmFields['codes'][$name] ?? null;
			if ($entityName === null)
			{
				if ($isNew)
				{
					// A reference to a field the form can not carry is an error of the input, never
					// a reason to create a user field behind the caller. One wording answers both
					// cases the check can not tell apart without reading the whole CRM: a field of
					// no entity at all, and a field of an entity the scheme leaves out. Both are
					// true statements about the scheme, and neither promises the field exists
					// elsewhere.
					$result->addError(new Error(
						sprintf('CRM field `%s` is not part of the scheme of the form', $name),
						WebFormPath::property($path, Section\Field::FIELD_NAME),
					));
				}

				continue;
			}

			if (
				$field->type === FieldTable::TYPE_ENUM_RESOURCEBOOKING
				&& !Entity::isSchemeSupportEntity((int)$schemeId, (int)\CCrmOwnerType::ResolveID($entityName))
			)
			{
				// The check lives in the legacy check action only and is never run on save. Here it
				// is part of the write.
				$result->addError(new Error(
					sprintf('CRM entity `%s` of the booking field is not part of the scheme of the form', $entityName),
					WebFormPath::property($path, Section\Field::FIELD_NAME),
				));
			}
		}
	}

	/**
	 * @param string|null $storedType type of the row this name is stored by, null when it is new
	 * @param array<string, string> $knownTypes
	 */
	private static function validateFieldType(
		Section\Field $field,
		string $path,
		bool $isNew,
		?string $storedType,
		array $knownTypes,
		Result $result,
	): void
	{
		$typePath = WebFormPath::property($path, Section\Field::FIELD_TYPE);
		if ($field->type === null)
		{
			if ($isNew)
			{
				$result->addError(new Error('Type of a new form field is required', $typePath));
			}

			return;
		}

		if (!isset($knownTypes[$field->type]))
		{
			// Legacy replaced an unknown type with a string one silently.
			$result->addError(new Error(sprintf('Form field type `%s` is unknown', $field->type), $typePath));

			return;
		}

		if (in_array($field->type, Section\Field::UNSUPPORTED_TYPES, true) && $storedType !== $field->type)
		{
			// A field of such a type is valid only with the variants it offers, and those live in the
			// service data of the field, which this contract does not carry. Neither creating one nor
			// turning a stored field into one can fill them, and a form holding a booking field
			// without its resources is refused by booking itself later on. A field already stored
			// under that type keeps its service data, so repeating the type is not a change at all,
			// while a renamed field is a new one and is refused like any other.
			$message = $isNew
				? 'A field of type `%s` can not be created through this contract:'
					. ' the variants it offers are not part of it'
				: 'A stored field can not be turned into a `%s` one:'
					. ' the variants it offers are not part of this contract'
			;

			$result->addError(new Error(sprintf($message, $field->type), $typePath));
		}
	}

	/**
	 * @param array<string, true> $fieldNames
	 */
	private static function validateDependencies(
		WebFormChangeSet $changeSet,
		array $fieldNames,
		Result $result,
	): void
	{
		if (!$changeSet->has(WebForm::SECTION_DEPENDENCIES))
		{
			return;
		}

		/** @var Section\DependencyGroup[] $groups */
		$groups = $changeSet->get(WebForm::SECTION_DEPENDENCIES);
		$dictionary = Dictionary::instance()->getDeps();
		$events = array_column($dictionary['condition']['events'] ?? [], 'id');
		$operations = array_column($dictionary['condition']['operations'] ?? [], 'id');
		$operations[] = self::EXTRA_DEPENDENCY_OPERATION;
		$actionTypes = array_column($dictionary['action']['types'] ?? [], 'id');

		foreach ($groups as $groupIndex => $group)
		{
			$groupPath = WebFormPath::item(WebForm::SECTION_DEPENDENCIES, $groupIndex);
			if ($group->logic !== null && !in_array($group->logic, ['and', 'or'], true))
			{
				$result->addError(new Error(
					sprintf('Dependency group logic `%s` is unknown', $group->logic),
					WebFormPath::property($groupPath, Section\DependencyGroup::FIELD_LOGIC),
				));
			}

			$rulesPath = WebFormPath::property($groupPath, Section\DependencyGroup::FIELD_RULES);
			if (($group->rules ?? []) === [])
			{
				// A group is stored by its rules and has no identity of its own, so one without them
				// is nothing the storage can keep. Accepted, it would leave the collection shorter
				// than the one the caller sent.
				$result->addError(new Error('Dependency group carries no rules', $rulesPath));
			}

			foreach ($group->rules ?? [] as $ruleIndex => $rule)
			{
				$rulePath = WebFormPath::item($rulesPath, $ruleIndex);
				$conditionPath = WebFormPath::property($rulePath, Section\DependencyRule::FIELD_CONDITION);
				$actionPath = WebFormPath::property($rulePath, Section\DependencyRule::FIELD_ACTION);

				self::validateDependencyReference(
					$rule->condition?->fieldName,
					WebFormPath::property($conditionPath, Section\DependencyCondition::FIELD_FIELD_NAME),
					$fieldNames,
					$result,
				);
				self::validateDependencyReference(
					$rule->action?->fieldName,
					WebFormPath::property($actionPath, Section\DependencyAction::FIELD_FIELD_NAME),
					$fieldNames,
					$result,
				);
				self::validateDependencyValue(
					$rule->condition?->event,
					WebFormPath::property($conditionPath, Section\DependencyCondition::FIELD_EVENT),
					$events,
					$result,
				);
				self::validateDependencyValue(
					$rule->condition?->operation,
					WebFormPath::property($conditionPath, Section\DependencyCondition::FIELD_OPERATION),
					$operations,
					$result,
				);
				self::validateDependencyValue(
					$rule->action?->type,
					WebFormPath::property($actionPath, Section\DependencyAction::FIELD_TYPE),
					$actionTypes,
					$result,
				);
			}
		}
	}

	/**
	 * A rule points only at a field the form ends up with: removing a field removes the rules
	 * referring to it, and the input may not bring back a reference the composition has lost.
	 *
	 * @param array<string, true> $fieldNames
	 */
	private static function validateDependencyReference(
		?string $fieldName,
		string $path,
		array $fieldNames,
		Result $result,
	): void
	{
		if ((string)$fieldName === '')
		{
			$result->addError(new Error('Field of the dependency rule is required', $path));

			return;
		}

		if (!isset($fieldNames[$fieldName]))
		{
			$result->addError(new Error(
				sprintf('Form field `%s` of the dependency rule does not belong to the form', $fieldName),
				$path,
			));
		}
	}

	/**
	 * @param string[] $allowed
	 */
	private static function validateDependencyValue(
		?string $value,
		string $path,
		array $allowed,
		Result $result,
	): void
	{
		if ($value === null)
		{
			$result->addError(new Error('Value of the dependency rule is required', $path));

			return;
		}

		if (!in_array($value, $allowed, true))
		{
			// Legacy replaced an unknown value with the first one of the dictionary silently.
			$result->addError(new Error(sprintf('Value `%s` is not allowed here', $value), $path));
		}
	}

	/**
	 * @param null|array{codes: array<string, string>, entityFields: array<string, array<string, true>>} $crmFields
	 */
	private static function validatePresetFields(
		WebFormChangeSet $changeSet,
		?array $crmFields,
		Result $result,
	): void
	{
		if (!$changeSet->has(WebForm::SECTION_PRESET_FIELDS))
		{
			return;
		}

		/** @var Section\PresetField[] $presetFields */
		$presetFields = $changeSet->get(WebForm::SECTION_PRESET_FIELDS);
		foreach ($presetFields as $index => $presetField)
		{
			$path = WebFormPath::item(WebForm::SECTION_PRESET_FIELDS, $index);
			$entityName = (string)$presetField->entityName;
			$fieldName = (string)$presetField->fieldName;

			// All three attributes are required by the storage and have no default of their own:
			// left unspecified they surface as a raw error of the storage.
			$isSpecified = true;
			foreach ([
				Section\PresetField::FIELD_ENTITY_NAME => $presetField->entityName,
				Section\PresetField::FIELD_FIELD_NAME => $presetField->fieldName,
				Section\PresetField::FIELD_VALUE => $presetField->value,
			] as $attribute => $value)
			{
				if ($value === null)
				{
					$result->addError(new Error(
						sprintf('Attribute `%s` of the preset value is required', $attribute),
						WebFormPath::property($path, $attribute),
					));
					$isSpecified = false;
				}
			}

			if (!$isSpecified || $crmFields === null)
			{
				continue;
			}

			if (!isset($crmFields['entityFields'][$entityName][$fieldName]))
			{
				// Read on the entities of the scheme alone, the way the check of the fields is.
				$result->addError(new Error(
					sprintf(
						'CRM field `%s` of the entity `%s` is not part of the scheme of the form',
						$fieldName,
						$entityName,
					),
					WebFormPath::property($path, Section\PresetField::FIELD_FIELD_NAME),
				));
			}
		}
	}

	private function validateResponsible(WebFormChangeSet $changeSet, Result $result): void
	{
		if (!$changeSet->has(self::RESPONSIBLE_USER_IDS_PATH))
		{
			return;
		}

		/** @var int[] $userIds */
		$userIds = $changeSet->get(self::RESPONSIBLE_USER_IDS_PATH);
		$suitableIds = $this->loadSuitableUserIds($userIds);

		$seenIds = [];
		foreach ($userIds as $index => $userId)
		{
			$path = WebFormPath::item(self::RESPONSIBLE_USER_IDS_PATH, $index);
			if ($userId <= 0)
			{
				$result->addError(new Error('Responsible user is not specified', $path));

				continue;
			}

			if (isset($seenIds[$userId]))
			{
				// A repeated user is a meaningful queue entry in legacy, so the storage keeps it.
				// Collapsing it here would be the same quiet substitution this contract replaces
				// with an error.
				$result->addError(new Error(
					sprintf('Responsible user `%d` is specified more than once', $userId),
					$path,
				));

				continue;
			}

			$seenIds[$userId] = true;
			if (!isset($suitableIds[$userId]))
			{
				// One wording for every unusable reference, and no value in the text: told apart,
				// "does not exist" and "is not suitable" would answer whether a user of the portal
				// exists to an application holding the crm scope alone.
				$result->addError(new Error('User can not be responsible for the form', $path));
			}
		}
	}

	/**
	 * The work time check of the form lives on the rows of its queue of responsible users and nowhere
	 * else: every row carries the flag, and a form without a queue has no row to carry it. Asking to
	 * switch the check on while the form ends up with an empty queue is therefore a request the
	 * storage can not keep, and it is refused instead of being answered with a success the next read
	 * would contradict.
	 *
	 * Switching the check off needs no queue: a form without one reads the check as off anyway.
	 */
	private static function validateCheckWorkTime(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		$path = self::CHECK_WORK_TIME_PATH;
		if (!$changeSet->has($path) || !(bool)$changeSet->get($path))
		{
			return;
		}

		$userIds = $changeSet->has(self::RESPONSIBLE_USER_IDS_PATH)
			? $changeSet->get(self::RESPONSIBLE_USER_IDS_PATH)
			: ($storedForm?->responsible?->userIds ?? [])
		;
		if ($userIds !== [])
		{
			return;
		}

		$result->addError(new Error(
			'Work time check is kept on the queue of responsible users, so a form without one can not hold it',
			$path,
		));
	}

	/**
	 * Users of the input that may take a turn in the queue, keyed by identifier. Only the ones the
	 * input names are asked about, and each of them once.
	 *
	 * @param int[] $userIds
	 * @return array<int, true>
	 */
	private function loadSuitableUserIds(array $userIds): array
	{
		return self::toIdMap(($this->suitableUserIdsLoader)(self::toAskedIds($userIds)));
	}

	/**
	 * An agreement of the input has to be one a form may be given: existing and active. An agreement
	 * the form already holds passes whatever its state - it is not this request that bound it, and a
	 * client reading the form and sending it back is not the place to answer for it.
	 */
	private function validateAgreements(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		Result $result,
	): void
	{
		if (!$changeSet->has(self::AGREEMENT_ITEMS_PATH))
		{
			return;
		}

		/** @var Section\AgreementItem[] $items */
		$items = $changeSet->get(self::AGREEMENT_ITEMS_PATH);
		$usableIds = $this->loadUsableAgreementIds(array_map(
			static fn (Section\AgreementItem $item): int => (int)$item->agreementId,
			$items,
		));
		foreach ($storedForm?->agreements?->items ?? [] as $storedItem)
		{
			$usableIds[(int)$storedItem->agreementId] = true;
		}

		foreach ($items as $index => $item)
		{
			$path = WebFormPath::property(
				WebFormPath::item(self::AGREEMENT_ITEMS_PATH, $index),
				Section\AgreementItem::FIELD_AGREEMENT_ID,
			);
			$agreementId = (int)$item->agreementId;
			if ($agreementId <= 0)
			{
				$result->addError(new Error('Agreement is not specified', $path));

				continue;
			}

			if (!isset($usableIds[$agreementId]))
			{
				$result->addError(new Error('Agreement can not be used in the form', $path));
			}
		}
	}

	/**
	 * Agreements of the input a form may be given, keyed by identifier.
	 *
	 * @param int[] $agreementIds
	 * @return array<int, true>
	 */
	private function loadUsableAgreementIds(array $agreementIds): array
	{
		return self::toIdMap(($this->usableAgreementIdsLoader)(self::toAskedIds($agreementIds)));
	}

	/**
	 * The identifiers a read of the references is asked about: the ones the input names, each once,
	 * and none of the ones that name nothing.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function toAskedIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			$ids,
			static fn (int $id): bool => $id > 0,
		)));
	}

	/**
	 * @param int[] $ids
	 * @return array<int, true>
	 */
	private static function toIdMap(array $ids): array
	{
		$map = [];
		foreach ($ids as $id)
		{
			$map[(int)$id] = true;
		}

		return $map;
	}

	/**
	 * @param array<string, true> $fieldNames
	 */
	private static function validateAdIntegrations(
		WebFormChangeSet $changeSet,
		?WebForm $storedForm,
		array $fieldNames,
		Result $result,
	): void
	{
		if (!$changeSet->has(self::ADS_PATH))
		{
			return;
		}

		$storedLinks = self::toLinksByOrigin($storedForm);

		/** @var Section\AdIntegration[] $ads */
		$ads = $changeSet->get(self::ADS_PATH);
		$seenOrigins = [];
		foreach ($ads as $index => $ad)
		{
			$path = WebFormPath::item(self::ADS_PATH, $index);

			$origin = self::originOf($ad);
			if (isset($seenOrigins[$origin]))
			{
				// A link is the provider, the account and the ad form, and the mechanism owning the
				// links reads a repeat of one as a link it does not hold yet: it would create a second
				// row and subscribe to the ad account a second time.
				$result->addError(new Error('This ad link is specified more than once', $path));

				continue;
			}

			$seenOrigins[$origin] = true;
			self::validateAdLinkNames($ad, $path, $storedLinks, $result);
			if ($ad->direction !== null && $ad->direction !== AdsFormLinkTable::LINK_DIRECTION_IMPORT)
			{
				// The mechanism owning the links skips a direction of its own silently, so an
				// export link sent here would answer with success and change nothing.
				$result->addError(new Error(
					'Only an ad link importing leads can be written here',
					WebFormPath::property($path, Section\AdIntegration::FIELD_DIRECTION),
				));
			}

			foreach ([
				Section\AdIntegration::FIELD_PROVIDER_CODE => $ad->providerCode,
				Section\AdIntegration::FIELD_ACCOUNT_ID => $ad->accountId,
				Section\AdIntegration::FIELD_FORM_ID => $ad->formId,
			] as $attribute => $value)
			{
				if ((string)$value === '')
				{
					// Legacy dropped such a link without a word.
					$result->addError(new Error(
						sprintf('Attribute `%s` of the ad link is required', $attribute),
						WebFormPath::property($path, $attribute),
					));
				}
			}

			$mappingsPath = WebFormPath::property($path, Section\AdIntegration::FIELD_FIELD_MAPPINGS);
			foreach ($ad->fieldMappings ?? [] as $mappingIndex => $mapping)
			{
				self::validateAdFieldMapping(
					$mapping,
					WebFormPath::item($mappingsPath, $mappingIndex),
					$fieldNames,
					$result,
				);
			}
		}
	}

	private static function validateAdProviders(WebFormChangeSet $changeSet, Result $result): void
	{
		if (!$changeSet->has(self::ADS_PATH))
		{
			return;
		}

		/** @var Section\AdIntegration[] $ads */
		$ads = $changeSet->get(self::ADS_PATH);
		$seenProviders = [];
		foreach ($ads as $index => $ad)
		{
			$provider = (string)$ad->providerCode;
			if ($provider === '' || $provider === 'vkontakte')
			{
				continue;
			}

			if (isset($seenProviders[$provider]))
			{
				$result->addError(new Error(
					'This ad provider is specified more than once',
					WebFormPath::item(self::ADS_PATH, $index),
				));
			}

			$seenProviders[$provider] = true;
		}
	}

	/**
	 * Links the form already holds, keyed the way the mechanism owning them identifies a link: by the
	 * provider, the account and the ad form.
	 *
	 * Only the links importing leads take part. The write path deals with those alone - a link of the
	 * export direction is neither read nor rewritten by it - so a stored export link naming the same
	 * provider, account and ad form is not the link an import of the input repeats: refusing the
	 * input by its names would refuse a link the write would have created without a word.
	 *
	 * @return array<string, Section\AdIntegration>
	 */
	private static function toLinksByOrigin(?WebForm $storedForm): array
	{
		$links = [];
		foreach ($storedForm?->integrations?->ads ?? [] as $link)
		{
			if ($link->direction !== AdsFormLinkTable::LINK_DIRECTION_IMPORT)
			{
				continue;
			}

			$links[self::originOf($link)] = $link;
		}

		return $links;
	}

	/**
	 * The names of a link the form already holds may only be sent as they are stored.
	 *
	 * The mechanism owning the links writes the name of the account and the name of the ad form when
	 * it creates a link and never rewrites them afterwards: a repeat carrying another name would be
	 * answered with success and change nothing. Writing the row past the mechanism is not an option -
	 * the row is its own, and the subscription of the link goes with it - so the request is refused
	 * instead, by the property that carries the name.
	 *
	 * A name the input leaves out means "not changed" and passes, so a client reading the form and
	 * sending it back whole is never refused; a name equal to the stored one passes as well.
	 *
	 * @param array<string, Section\AdIntegration> $storedLinks
	 */
	private static function validateAdLinkNames(
		Section\AdIntegration $ad,
		string $path,
		array $storedLinks,
		Result $result,
	): void
	{
		$storedLink = $storedLinks[self::originOf($ad)] ?? null;
		if ($storedLink === null)
		{
			return;
		}

		foreach ([
			Section\AdIntegration::FIELD_ACCOUNT_NAME => [$ad->accountName, $storedLink->accountName],
			Section\AdIntegration::FIELD_FORM_NAME => [$ad->formName, $storedLink->formName],
		] as $attribute => [$sent, $stored])
		{
			if ($sent === null || $sent === (string)$stored)
			{
				continue;
			}

			$result->addError(new Error(
				sprintf(
					'Attribute `%s` of an ad link the form already holds is set when the link is '
						. 'created and can not be changed here: send it as the form holds it or leave it out',
					$attribute,
				),
				WebFormPath::property($path, $attribute),
			));
		}
	}

	/** The way the mechanism owning the links tells one link from another. */
	private static function originOf(Section\AdIntegration $ad): string
	{
		return (string)$ad->providerCode . '/' . (string)$ad->accountId . '/' . (string)$ad->formId;
	}

	/**
	 * One mapping of an ad field onto a field of the form. Both names are required: the storage of
	 * the mappings declares both of its columns required, so a mapping missing either of them is
	 * refused there - by the mechanism owning the links, after the write of the section has started,
	 * and with the whole collection named instead of the element that carries the mistake.
	 *
	 * @param array<string, true> $fieldNames
	 */
	private static function validateAdFieldMapping(
		Section\AdFieldMapping $mapping,
		string $path,
		array $fieldNames,
		Result $result,
	): void
	{
		$crmFieldPath = WebFormPath::property($path, Section\AdFieldMapping::FIELD_CRM_FIELD_NAME);
		$crmFieldName = (string)$mapping->crmFieldName;
		if ($crmFieldName === '')
		{
			$result->addError(new Error('Form field of the mapping is required', $crmFieldPath));
		}
		elseif (!isset($fieldNames[$crmFieldName]))
		{
			$result->addError(new Error(
				sprintf('Form field `%s` of the mapping does not belong to the form', $crmFieldName),
				$crmFieldPath,
			));
		}

		if ((string)$mapping->adsFieldName === '')
		{
			$result->addError(new Error(
				'Ad field of the mapping is required',
				WebFormPath::property($path, Section\AdFieldMapping::FIELD_ADS_FIELD_NAME),
			));
		}
	}
}
