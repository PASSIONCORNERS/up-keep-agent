<?php

namespace Passioncorners\UpKeepAgent\Server;

/**
 * The versions behind the app, for up-keep's Stack panel.
 */
class StackReadings
{
    public function __construct(private string $osReleasePath = '/etc/os-release') {}

    /**
     * @return array{php: string, laravel: string, os: string|null}
     */
    public function read(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'os' => $this->os(),
        ];
    }

    private function os(): ?string
    {
        if (! is_readable($this->osReleasePath)
            || ! preg_match('/^PRETTY_NAME=(.*)$/m', (string) file_get_contents($this->osReleasePath), $matches)) {
            return null;
        }

        $name = trim($matches[1], "\"' \t");

        return $name === '' ? null : $name;
    }
}
