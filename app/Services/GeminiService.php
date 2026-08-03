<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    public function __construct()
    {
        //
    }

    public function createMessage()
    {
        $aiSetting = AiSetting::first();

        $systemInstruction = $aiSetting->system_prompt;
        $input = $aiSetting->user_prompt_template;
        $temperature = $aiSetting->temperature;
        $top_p = $aiSetting->top_p;
        $max_output_tokens = $aiSetting->max_output_tokens;
        $model = $aiSetting->model;

        $response = Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
        ])->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
            'model' => $model,
            'system_instruction' => $systemInstruction,
            'input' => $input,
            'generation_config' => [
                'temperature' => $temperature,
                'top_p' => $top_p,
                'max_output_tokens' => $max_output_tokens,
            ],
        ]);

        $response->throw();

        $data = $response->json();

        $message = collect($data['steps'] ?? [])
            ->firstWhere('type', 'model_output');

        $message = data_get($message, 'content.0.text');

        if (!is_string($message) || trim($message) === '') {
            throw new \RuntimeException('O Gemini não retornou uma mensagem válida.');
        }

        $message = trim($message);

        return $message;
    }
}
