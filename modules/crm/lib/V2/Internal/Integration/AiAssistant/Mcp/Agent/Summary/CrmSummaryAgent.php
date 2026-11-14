<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Agent\Summary;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet\Summary\SummaryToolSet;
use Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryRules;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loader::requireModule('aiassistant');

/**
 * The agent telling the summary of one CRM entity: a contact, a company or a deal.
 *
 * The rules of the answer are not written here: the prompt is assembled out of the parts of
 * {@see SummaryRules}, the one text the generating operation is worded by as well. A rule reworded
 * here would part ways with the summary the same chat delivers by a button.
 *
 * The agent takes every part of the rules but the JSON contract: it answers the chat with text,
 * while the operation is the one owing a strict structure. The shift of the accent by the type of the
 * entity is the other way round - the agent is the only one asking for it, because a deal reaches the
 * summary through the agent alone. The rules of the prompt buttons are the agent's alone for the same
 * reason: a button is pressed in this chat and nowhere else.
 *
 * The answer is grounded in two tools, and the opening paragraph tells them apart: a question about
 * the standing of the entity goes to the summary, a question about the conversations themselves goes
 * to the history of the communication. The questions of the buttons are routed there too and not by
 * the parts of the buttons alone: the routing is decided by the first paragraph the model reads, and a
 * question about the promises we made reads as a question about the standing of the entity until it is
 * named.
 *
 * The language of the portal is substituted and not named in the text, so a portal that is not
 * Russian is answered in its own language.
 *
 * The tools are taken from the set they belong to, and the grant is the one the set and the tool ask,
 * so the agent cannot be listed where the tools of it are already hidden.
 *
 * The switch of the portal is no part of that question, here as in the two of them: the agent is asked
 * before the tool is, and an agent gone from the catalogue takes with it the only way the model has of
 * telling anyone why the summary stopped answering. The other half of that is in the prompt: the rule
 * of retelling a refusal is taken by this agent and by nobody else, because the tool answering one is
 * called from here alone.
 */
final class CrmSummaryAgent extends BaseAgent
{
	public function __construct(
		private readonly SummaryAvailability $availability = new SummaryAvailability(),
	)
	{
	}

	public function getCode(): string
	{
		return 'crm_entity_summary';
	}

	/**
	 * The entry of the agent in the registry, and the one text that decides whether the agent is opened
	 * at all: the model picks an agent by reading the entries, and the prompt of this one is applied only
	 * after the pick. So the entry names both sides the agent answers - the standing of the entity and
	 * the history of the communication with it - and the questions the summary chat sends by a button,
	 * which read as questions about the conversations and not about the standing of anything.
	 */
	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM Summary Agent',
			'Marta AI CRM summary agent - answers about one CRM entity, a contact, a company or a deal, out'
				. ' of the structured data of the summary tools. Two sides of it: the standing of the entity -'
				. ' what happened with it, what deserves attention and what to do next - and the history of the'
				. ' communication with it, the calls, the letters and the dialogues of the open lines - what'
				. ' was said in the conversations, what the client objected to, what was promised to the client'
				. ' and how the recent calls went. The questions the summary chat offers by a button - what'
				. ' objections the client had, what we promised the client, what the recent calls with the'
				. ' client were - are the questions of this agent.',
		);
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'CRM Summary Agent Prompt',
			self::promptFor(Loc::getCurrentLang()),
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(SummaryToolSet::TOOLS);
	}

	public function canList(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}

	private static function promptFor(string $language): string
	{
		$intro = <<<INTRO
		Ты — Марта, ИИ-ассистент менеджера по продажам в CRM Битрикс24. Ты отвечаешь про один элемент CRM:
		контакт, компанию или сделку. Единственный источник фактов — данные инструментов get_crm_summary
		и get_crm_communications, и ничего, кроме них.

		Инструменты отвечают о разном. Спрашивают о положении дел по элементу — что происходит, на что
		обратить внимание, что делать дальше — бери данные get_crm_summary. Спрашивают про конкретные
		разговоры — о чём говорили, что прозвучало в звонке, письме или переписке открытой линии, какими
		были последние звонки — бери данные get_crm_communications. Нужны обе стороны — зови оба
		инструмента.

		История общения несёт краткое содержание разговора, которое написала речевая аналитика, и никогда
		не несёт самого разговора: дословных расшифровок звонков, текстов писем и переписки открытых линий
		у тебя нет. Просят дословную запись — скажи, что её нет, и перескажи по краткому содержанию.

		Вопросы кнопок-подсказок — о возражениях, об обещаниях клиенту и о последних звонках — это всегда
		вопросы о разговорах, как бы они ни были сформулированы. На них зови get_crm_communications, а по
		возражениям и обещаниям — оба инструмента сразу: сводка несёт краткое содержание только последнего
		звонка. Правила ответа на каждый из них — ниже, в частях про кнопки.
		INTRO;

		return implode("\n\n", [
			$intro,
			SummaryRules::blocks(),
			SummaryRules::entityTypeAccents(),
			SummaryRules::principles(),
			SummaryRules::sectionStates(),
			SummaryRules::incompleteness(),
			SummaryRules::refusal(),
			SummaryRules::injectionGuard(),
			SummaryRules::communicationsInjectionGuard(),
			SummaryRules::buttonAnswer(),
			SummaryRules::objectionsButton(),
			SummaryRules::promisesButton(),
			SummaryRules::recentCallsButton(),
			SummaryRules::responseLanguage($language),
		]);
	}
}
