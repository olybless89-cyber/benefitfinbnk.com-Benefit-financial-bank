<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AppearanceSettings;

class ResetAppearanceSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appearance:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset appearance settings to default values';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $appearance = AppearanceSettings::first();
        
        if (!$appearance) {
            $this->error('Appearance settings not found. Make sure to run migrations first.');
            return 1;
        }
        
        $appearance->update([
            'primary_color' => '#0ea5e9',
            'primary_color_dark' => '#0369a1',
            'primary_color_light' => '#38bdf8',
            'secondary_color' => '#14b8a6',
            'secondary_color_dark' => '#0f766e',
            'secondary_color_light' => '#5eead4',
            'text_color' => '#111827',
            'bg_color' => '#f9fafb',
            'sidebar_bg_color' => '#1e293b',
            'sidebar_text_color' => '#ffffff',
            'card_bg_color' => '#ffffff',
            'use_gradient' => true,
            'gradient_direction' => 'to right',
            'custom_css' => null,
            'disable_animations' => false,
            'notes' => 'Default appearance settings reset via command',
        ]);
        
        $this->info('Appearance settings have been reset to default values.');
        return 0;
    }
} 