<?php

declare(strict_types=1);

namespace Aidelnicek\Llm;

/**
 * Továrna pro vytváření LLM providerů.
 *
 * Provider se vybírá dle env proměnné LLM_PROVIDER (výchozí: 'openai').
 *
 * Přidání nového providera:
 *   1. Vytvořit src/Llm/NovyProvider.php implementující LlmInterface
 *   2. Přidat case do match níže
 *   3. Nastavit LLM_PROVIDER=novy_provider v K8s Secret
 */
class LlmFactory
{
    public static function create(): LlmInterface
    {
        $provider = getenv('LLM_PROVIDER') ?: 'openai';
        return match ($provider) {
            'openai' => new OpenAiProvider(),
            default  => throw new \InvalidArgumentException("Neznámý LLM provider: {$provider}"),
        };
    }
}
