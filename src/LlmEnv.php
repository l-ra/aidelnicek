<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Sdílené limity a výchozí hodnoty pro volání LLM (OpenAI / worker).
 */
final class LlmEnv
{
    public const DEFAULT_MAX_COMPLETION_TOKENS = 16000;

    /** Horní mez pro max_completion_tokens u API i v llm_worker (Pydantic le=128000). */
    public const MAX_COMPLETION_TOKENS_CAP = 128000;

    public static function maxCompletionTokens(): int
    {
        $raw = getenv('LLM_MAX_COMPLETION_TOKENS');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_MAX_COMPLETION_TOKENS;
        }

        return max(64, min(self::MAX_COMPLETION_TOKENS_CAP, (int) $raw));
    }
}
