<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Summary\GetSummary;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryRules;
use Bitrix\Crm\V2\Internal\Service\Summary\ClientSummaryService;
use Bitrix\Crm\V2\Internal\Service\Summary\DealSummaryService;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * The tool answering the summary of one CRM entity with the structure the domain service of its type
 * collected.
 *
 * It owns no rule of its own: the arguments are judged by the schema and by the args DTO, the entity
 * is judged by the service, and the answer travels as the wire form of the result untouched. The type
 * of the entity picks the service and nothing else - what a summary of it is made of belongs to that
 * service, not here. Whether the user is granted the summary at all is answered by
 * {@see SummaryAvailability} - the same measure the set and the agent ask - and it is asked here
 * because the index of tools looks no higher than the tool it lists.
 *
 * Only the grant is asked, and the switch of the portal is left out of it on purpose: a tool refused at
 * this step is gone from the catalogue, while a summary turned off on a portal is owed a refusal the
 * model can read and pass on.
 *
 * That refusal travels as a successful result, which is the only carrier it has. The base class turns
 * an unsuccessful one into an exception, and the controller of the assistant answers every exception
 * of a tool with one and the same error - a refusal worded as a failure would reach the person as "the
 * summary broke" and never as "the admin of the portal turned it off". The mark of it is a field of
 * its own and not a value inside the data of a summary, and the switch is read before anything is read
 * out of the storage: a scenario turned off costs no read.
 */
final class GetSummaryTool extends AbstractTool
{
	/**
	 * DTO-01: the only reason the tool refuses an answer for so far.
	 */
	private const REFUSAL_DISABLED_ON_PORTAL = 'summary_disabled_on_portal';

	public function __construct(
		TracedLogger $tracedLogger,
		private readonly ?ClientSummaryService $summaryService = null,
		private readonly SummaryAvailability $availability = new SummaryAvailability(),
		private readonly ?DealSummaryService $dealSummaryService = null,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function canList(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'get_crm_summary',
			description: self::description(),
		))
			->setProperties([
				(new StringProperty(
					id: 'entityType',
					description: 'Type of the entity to summarize.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setEnum(GetSummaryToolDto::ENTITY_TYPES)
				,
				(new IntegerProperty(
					id: 'entityId',
					description: 'Identifier of the entity of that type.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setMinimum(1)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return GetSummaryToolDto::class;
	}

	/**
	 * @param GetSummaryToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		if (!$this->availability->isEnabledOnPortal())
		{
			return self::refusalOfTheSwitch();
		}

		$entityTypeId = CCrmOwnerType::ResolveID((string)$args->entityType);
		$entityId = (int)$args->entityId;

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return ToolResult::failEntityTypeNotSupported($entityTypeId);
		}

		// an entity that is not there is refused before a service is asked: a summary of a client
		// answers the same empty sections for a client nobody ever wrote a line about and for a client
		// that does not exist, and an empty summary is no refusal. The rights are not asked about here
		// on purpose - the service is the one that owns them - so the probe is the cheapest read that
		// answers the question: the identifier and nothing else. What it refuses with is what a service
		// refuses a missing right with, so the two answers say nothing about which of them happened.
		if (!self::exists($factory, $entityId))
		{
			return ToolResult::failNotFound();
		}

		$result = $this->collect($entityTypeId, $entityId, $args->getUserId());
		if (!$result->isSuccess())
		{
			return self::refusalOf($result);
		}

		return ToolResult::success(...$result->getData()['summary']->toArray());
	}

	/**
	 * DTO-01: what a portal with the summary turned off is answered with. The mark tells the model this
	 * is no summary, and the words are the ones it passes on to the person - so the wording lives in the
	 * localisation of the module and is repeated by no rule of the prompt.
	 */
	private static function refusalOfTheSwitch(): ToolResult
	{
		return ToolResult::success(
			isRefused: true,
			refusalReason: self::REFUSAL_DISABLED_ON_PORTAL,
			refusalMessage: (string)Loc::getMessage('CRM_V2_MCP_TOOL_GET_SUMMARY_REFUSAL_DISABLED_ON_PORTAL'),
		);
	}

	/**
	 * What the tool answers with, and the rules the answer is built by: the blocks and the states of a
	 * section, taken from {@see SummaryRules} instead of being worded here a second time.
	 *
	 * The lead says where the data of the conversations ends, and the tool that carries the rest is
	 * named: the summary knows the short content of the last call and no other, and a question about
	 * the whole history reads like a question about the standing of the item. Choosing between two
	 * tools, the model weighs what their descriptions say they carry, so the boundary is stated where
	 * the choice is made and not in the prompt alone.
	 *
	 * The guard of the texts travels with them for the same reason: every string field of the answer is
	 * written by a person who edits the card, and an agent calling this tool without the prompt of ours
	 * is told nowhere else that those texts are data and never instructions.
	 */
	private static function description(): string
	{
		$lead = <<<LEAD
		Возвращает структурированные данные для сводки по одному элементу CRM: контакту, компании или
		сделке. Данные только для чтения и уже отфильтрованы по правам вызывающего пользователя, своего
		текста инструмент не пишет. В ответе — тип и идентификатор элемента («entityType», «entityId»),
		признак неполноты («isIncomplete») и разделы данных.

		Из разговоров сводка несёт краткое содержание только последнего звонка. Вопрос про всю историю
		общения — что звучало в звонках, письмах и диалогах открытых линий, какие были возражения, что
		мы обещали клиенту — по этим данным не закрывается: за историей зови get_crm_communications, а
		нужны обе стороны — зови оба инструмента.
		LEAD;

		return implode("\n\n", [
			$lead,
			SummaryRules::blocks(),
			SummaryRules::sectionStates(),
			SummaryRules::injectionGuard(),
		]);
	}

	/**
	 * The service of the type, asked the way that type asks about a summary.
	 *
	 * The two services share no interface and do not take the same arguments: the summary of a deal
	 * knows the type of what it collects without being told it. So the choice is a branch of two calls
	 * and not one call of a chosen collector; what they do share is the shape of the answer.
	 */
	private function collect(int $entityTypeId, int $entityId, int $userId): Result
	{
		return $entityTypeId === CCrmOwnerType::Deal
			? $this->dealSummaryService()->collect($entityId, $userId)
			: $this->clientSummaryService()->collect($entityTypeId, $entityId, $userId)
		;
	}

	/**
	 * One row of the primary key and no more. The count of the factory answers the same question and
	 * costs two reads more for it: it asks the storage whether the table is there at all.
	 */
	private static function exists(Factory $factory, int $entityId): bool
	{
		return $factory->getDataClass()::getRow([
			'select' => [Item::FIELD_NAME_ID],
			'filter' => ['=' . Item::FIELD_NAME_ID => $entityId],
		]) !== null;
	}

	/**
	 * The service is built per call and asks for nothing: it is a collector without state of its own.
	 * An instance handed to the constructor is used instead, which is how the tests reach the branches
	 * of the answer without a database behind them.
	 */
	private function clientSummaryService(): ClientSummaryService
	{
		return $this->summaryService ?? new ClientSummaryService();
	}

	private function dealSummaryService(): DealSummaryService
	{
		return $this->dealSummaryService ?? new DealSummaryService();
	}

	/**
	 * The refusals of the services that have an answer of their own in the contract of the tool; anything
	 * else they decline to do travels as the message they declined with.
	 *
	 * The three of them share one answer, and it is the one that says the least. An entity the user may
	 * not read is refused the way an entity that is not there is: answered apart, the pair tells whoever
	 * may not see an entity that an entity of that identifier exists, and an identifier is guessed by
	 * counting. Nothing readable is kept from anyone by that - a user who may read the entity is
	 * answered the summary of it as before.
	 *
	 * A recurring deal joins them: it is a template of the deals to come and not a deal that happened,
	 * the contract of the tool knows no third answer for it, and the model is owed the same one either
	 * way.
	 *
	 * The services word each refusal in a code of their own, so the tool knows the code of every one of
	 * them instead of expecting a shared string of the three.
	 */
	private static function refusalOf(Result $result): ToolResult
	{
		$toldAsNotFound = [
			ClientSummaryService::ERROR_ACCESS_DENIED,
			DealSummaryService::ERROR_ACCESS_DENIED,
			DealSummaryService::ERROR_UNSUPPORTED_DEAL,
		];

		foreach ($result->getErrors() as $error)
		{
			if (in_array($error->getCode(), $toldAsNotFound, true))
			{
				return ToolResult::failNotFound();
			}
		}

		return ToolResult::fail(implode("\n", $result->getErrorMessages()));
	}
}
