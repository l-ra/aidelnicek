<?php

declare(strict_types=1);

namespace Aidelnicek\Llm;

interface LlmInterface
{
    /**
     * Odešle prompt modelu a vrátí textovou odpověď.
     *
     * @param string $systemPrompt  Systémový kontext (role/instrukce modelu)
     * @param string $userPrompt    Uživatelský prompt (konkrétní zadání)
     * @param array  $options       Volitelné: 'temperature' (float), 'max_completion_tokens' (int),
     *                              'user_id' (int) — pro logování
     * @return string               Textová odpověď modelu
     * @throws \RuntimeException    Při HTTP chybě, timeoutu nebo neplatném tokenu
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string;

    /** Vrátí identifikátor poskytovatele, např. 'openai' */
    public function getName(): string;

    /** Vrátí název použitého modelu, např. 'gpt-4o' */
    public function getModel(): string;
}
