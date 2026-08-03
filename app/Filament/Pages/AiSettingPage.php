<?php

namespace App\Filament\Pages;

use App\Enums\AiProviderEnum;
use App\Models\AiSetting;
use Filament\Pages\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;

class AiSettingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected string $view = 'filament.pages.ai-setting-page';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('AI Setting');
    }

    public function mount(): void
    {
        $configuration = AiSetting::first();

        if ($configuration) {
            $this->form->fill($configuration->toArray());
        } else {
            $this->form->fill();
        }
    }

    public function form($form)
    {
        return $form
            ->schema([
                Select::make('provider')
                    ->label(__('Provider'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->options(AiProviderEnum::class)
                    ->columnSpan(2)
                    ->required(),
                TextInput::make('model')
                    ->label(__('Model'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->columnSpan(2)
                    ->required(),
                Textarea::make('system_prompt')
                    ->label(__('System Prompt'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->rows(20)
                    ->columnSpan(2)
                    ->required(),
                Textarea::make('user_prompt_template')
                    ->label(__('User Prompt Template'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->rows(20)
                    ->columnSpan(2)
                    ->required(),
                Group::make()
                    ->schema([
                        TextInput::make('temperature')
                            ->label(__('Temperature'))
                            ->validationMessages([
                                'required' => __('This field is required'),
                            ])
                            ->columnSpan(1)
                            ->numeric()
                            ->required(),
                        TextInput::make('top_p')
                            ->label(__('Top P'))
                            ->validationMessages([
                                'required' => __('This field is required'),
                            ])
                            ->columnSpan(1)
                            ->numeric()
                            ->required(),
                        TextInput::make('max_output_tokens')
                            ->label(__('Max Output Tokens'))
                            ->validationMessages([
                                'required' => __('This field is required'),
                            ])
                            ->columnSpan(1)
                            ->integer()
                            ->required(),
                    ])->columns(3)->columnSpanFull(),
                Actions::make([
                    Action::make('save')
                        ->label(__('Save'))
                        ->action('save')
                        ->color('primary'),
                ])->columnSpanFull(),
            ])->columns(4)->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            AiSetting::updateOrCreate(
                [
                    'id' => AiSetting::query()->value('id') ?? 1,
                ],
                [
                    'provider' => $data['provider'],
                    'model' => $data['model'],
                    'system_prompt' => $data['system_prompt'],
                    'user_prompt_template' => $data['user_prompt_template'],
                    'temperature' => $data['temperature'],
                    'top_p' => $data['top_p'],
                    'max_output_tokens' => $data['max_output_tokens'],
                ]
            );

            Notification::make()
                ->success()
                ->title(__('Configuration Saved Successfully!'))
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title(__('Failed to save configuration'))
                ->send();
            Log::error($e->getMessage());
            return;
        }
    }
}
