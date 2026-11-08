<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Localization\Loc;

abstract class AbstractNotifyResult extends AbstractNotifyWithFiles
{
	protected function buildResultTextAttach(string $resultText): ?\CIMMessageParamAttach
	{
		if ($resultText === '')
		{
			return null;
		}

		$resultText = htmlspecialchars_decode(htmlspecialcharsback($resultText), ENT_QUOTES);
		$resultText = trim(\CTextParser::clearAllTags($resultText));
		$resultText = str_replace(
			["&#91;", "&#93;"],
			["[", "]"],
			$resultText,
		);
		$resultText = preg_replace('/\n{3,}/', "\n\n", $resultText);

		if ($resultText === '')
		{
			return null;
		}

		$attach = new \CIMMessageParamAttach();
		$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_RESULT_TEXT') . '[/b][br]');
		$attach->AddMessage($resultText);

		return $attach;
	}
}
