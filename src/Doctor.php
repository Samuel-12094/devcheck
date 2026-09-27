<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Point d'entree unique : execute tous les tests et renvoie un Report.
 */
final class Doctor
{
    /** @param array<string,mixed>|null $config */
    public function __construct(private array $config)
    {
    }

    public static function fromProjectRoot(): self
    {
        /** @var array<string,mixed> $cfg */
        $cfg = require dirname(__DIR__) . '/config.php';
        return new self($cfg);
    }

    /**
     * Suites de tests, dans l'ordre d'affichage.
     *
     * @return array<string,callable(array<string,mixed>):list<Result>>
     */
    private function suites(): array
    {
        return [
            'web'   => static fn (array $c) => PhpChecks::run($c),
            'db'    => static fn (array $c) => DbChecks::run($c),
            'fs'    => static fn (array $c) => FsChecks::run($c),
            'tools' => static fn (array $c) => ToolChecks::run($c),
            'net'   => static fn (array $c) => NetChecks::run($c),
        ];
    }

    public function run(): Report
    {
        $report = new Report();

        foreach ($this->suites() as $name => $suite) {
            $t0 = microtime(true);
            try {
                $report->add(...$suite($this->config));
            } catch (\Throwable $e) {
                $report->add(new Result(
                    'Interne',
                    'Suite de tests interrompue',
                    Result::KO,
                    get_class($e),
                    $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                ));
            }
            $report->addTiming((string) $name, (microtime(true) - $t0) * 1000);
        }

        $report->seal();
        return $report;
    }
}
