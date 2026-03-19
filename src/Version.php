<?php

declare(strict_types=1);

namespace Aidelnicek;

class Version
{
    /** @return array{version: string, build_date: string}|null */
    public static function get(string $projectRoot): ?array
    {
        $versionFile = $projectRoot . '/version.json';
        if (file_exists($versionFile)) {
            $json = @file_get_contents($versionFile);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data) && isset($data['version'], $data['build_date'])) {
                    $date = date_create($data['build_date']);
                    $formatted = $date ? $date->format('j. n. Y H:i') : (string) $data['build_date'];
                    return [
                        'version' => (string) $data['version'],
                        'build_date' => $formatted,
                    ];
                }
            }
        }

        $gitDir = $projectRoot . '/.git';
        if (is_dir($gitDir)) {
            $version = self::runGit($projectRoot, 'rev-parse --short HEAD');
            $buildDate = self::runGit($projectRoot, 'log -1 --format=%ci');
            if ($version !== null && $buildDate !== null) {
                $date = date_create($buildDate);
                $formatted = $date ? $date->format('j. n. Y H:i') : substr($buildDate, 0, 19);
                return [
                    'version' => $version,
                    'build_date' => $formatted,
                ];
            }
        }

        return null;
    }

    private static function runGit(string $cwd, string $args): ?string
    {
        $cmd = 'git ' . $args . ' 2>/dev/null';
        $output = @shell_exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd);
        $result = $output !== null ? trim($output) : '';
        return $result !== '' ? $result : null;
    }
}
