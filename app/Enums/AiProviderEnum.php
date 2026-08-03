<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AiProviderEnum: string implements HasLabel
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Gemini',
        };
    }
}
