<?php

namespace App\Services;

class DurationFormatterService
{
    public function format(int $seconds): string
    {
        if ($seconds === 0) {
            return '0 seconds';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = array_filter([
            $hours > 0 ? $this->pluralize($hours, 'hour') : null,
            $minutes > 0 ? $this->pluralize($minutes, 'minute') : null,
            $secs > 0 || empty(array_filter([$hours, $minutes])) 
                ? $this->pluralize($secs, 'second') : null,
        ]);
        
        return implode(' ', $parts);
    }
    
    private function pluralize(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count !== 1 ? 's' : '');
    }
}