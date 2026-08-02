<?php

namespace App\Filament\Pages;

use App\Models\Configuration;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;

class ConfigurationPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.pages.configuration-page';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Configuration');
    }

    public function mount(): void
    {
        $configuration = Configuration::first();

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
                Textarea::make('query')
                    ->label(__('Query'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->rows(5)
                    ->columnSpan(2)
                    ->required(),
                Textarea::make('cookie')
                    ->label(__('Cookie'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->rows(5)
                    ->columnSpan(2)
                    ->required(),
                TextInput::make('affiliate_endpoint')
                    ->label(__('Affiliate Endpoint'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->columnSpan(2)
                    ->required(),
                TextInput::make('base_url')
                    ->label(__('Base URL'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->columnSpan(2)
                    ->required(),
                TextInput::make('affiliate_tag')
                    ->label(__('Affiliate Tag'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->required(),
                TextInput::make('csrf_token')
                    ->label(__('CSRF Token'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->required(),
                TextInput::make('max_items')
                    ->label(__('Max Items'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->integer()
                    ->required(),
                TextInput::make('max_items_name')
                    ->label(__('Max Items Name'))
                    ->validationMessages([
                        'required' => __('This field is required'),
                    ])
                    ->integer()
                    ->required(),
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

            Configuration::updateOrCreate(
                [
                    'id' => Configuration::query()->value('id') ?? 1,
                ],
                [
                    'query' => $data['query'],
                    'affiliate_tag' => $data['affiliate_tag'],
                    'cookie' => $data['cookie'],
                    'csrf_token' => $data['csrf_token'],
                    'affiliate_endpoint' => $data['affiliate_endpoint'],
                    'base_url' => $data['base_url'],
                    'max_items' => $data['max_items'],
                    'max_items_name' => $data['max_items_name'],
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
