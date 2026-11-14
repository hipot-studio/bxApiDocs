<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

/**
 * Sample answer in the summary response contract: exactly four blocks in the order of the rules plus
 * the coverage note. Titles and language follow the rules the prompt sends to the model.
 *
 * Which of the three sample answers is given back is told by a development option, the way the stub
 * mode itself is. Without it the answer is the complete summary, and the other two are the outcomes a
 * live model produces on its own and nothing on a stand ever can: a summary that came incomplete and
 * an answer that is not the contract at all.
 */
final class ClientSummary implements StubInterface
{
	public const ANSWER_OPTION = 'dev_ai_stub_summary_answer';
	public const ANSWER_INCOMPLETE = 'incomplete';
	public const ANSWER_BROKEN = 'broken';

	private const INCOMPLETE_NOTE = 'Сводка собрана по части общения: расшифровки двух звонков ещё нет.';
	private const BROKEN_ANSWER = 'Не могу ответить на этот запрос.';

	public function makeStub(): string
	{
		$answer = (string)Option::get('crm', self::ANSWER_OPTION, '');
		if ($answer === self::ANSWER_BROKEN)
		{
			return self::BROKEN_ANSWER;
		}

		return Json::encode([
			'blocks' => [
				[
					'title' => 'Статус сделки',
					'content' => 'Оптовый поставщик упаковки, решение принимает коммерческий директор.'
						. ' Активная сделка на 450 000 ₽ на этапе переговоров.',
				],
				[
					'title' => 'События и договорённости',
					'content' => "— Обсудили объёмы поставки на квартал.\n"
						. '— Пообещали прислать коммерческое предложение до конца недели.',
				],
				[
					'title' => 'Риски и сигналы',
					'content' => "— Возражение по цене осталось без ответа.\n"
						. '— Сроки поставки сдвигались дважды.',
				],
				[
					'title' => 'Что делать дальше',
					'content' => 'Позвонить коммерческому директору и вернуться к цене, опираясь на объём'
						. ' квартальной поставки. Если разговор не состоится, прислать расчёт письмом.',
				],
			],
			'coverage_note' => $answer === self::ANSWER_INCOMPLETE ? self::INCOMPLETE_NOTE : null,
		]);
	}
}
