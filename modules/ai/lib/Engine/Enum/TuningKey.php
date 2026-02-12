<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Enum;

enum TuningKey: string
{
	case EngineText = 'engine_text';

	case EngineImage = 'engine_image';

	case CrmCopilotCallAssessmentEngineCode = 'crm_copilot_call_assessment_engine_code';
	case CrmCopilotFillItemFromCallEngineAudio = 'crm_copilot_fill_item_from_call_engine_audio';
	case CrmCopilotFillItemFromCallEngineText = 'crm_copilot_fill_item_from_call_engine_text';
	case CrmCopilotRepeatSaleEngineCode = 'crm_copilot_repeat_sale_engine_code';

	case ImChatAnswerProvider = 'im_chat_answer_provider';

	case LandingSiteImageProvider = 'landing_site_image_provider';
	case LandingSiteTextProvider = 'landing_site_text_provider';
	case LandingImageProvider = 'landing_image_provider';
	case LandingTextProvider = 'landing_text_provider';

	case TasksFlowsTextGenerateEngine = 'tasks_flows_text_generate_engine';
}
