<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Tests "Reseau" : ports locaux, DNS, acces sortant.
 */
final class NetChecks
{
    private const CAT = 'Reseau';

    /** @param array<string,mixed> $config */
    public static function run(array $config): array
    {
        $net = $config['net'];

        $out = [
            self::listeningPorts($net),
            self::loopback(),
            self::dns(),
        ];

        if (!empty($net['check_outbound'])) {
            $out[] = self::outbound($net);
        }

        return $out;
    }

    /** @param array<string,mixed> $net */
    private static function listeningPorts(array $net): Result
    {
        $timeout = (float) ($net['probe_timeout'] ?? 1.5);
        $open = [];
        $closed = [];

        foreach (array_keys($net['ports']) as $port) {
            $port = (int) $port;
            if (Support::isPortOpen('127.0.0.1', $port, $timeout)) {
                $open[$port] = $net['ports'][$port];
            } else {
                $closed[] = $port;
            }
        }

        $value = count($open) . ' port(s) ouvert(s)';

        return new Result(self::CAT, 'Ports locaux', Result::INFO, $value,
            'Ouverts : ' . (implode(', ', array_map(
                static fn ($p) => $p . ' (' . $net['ports'][$p] . ')',
                array_keys($open)
            )) ?: 'aucun')
            . ' | Fermes : ' . ($closed ? implode(', ', $closed) : 'aucun'));
    }

    private static function loopback(): Result
    {
        $host = gethostname() ?: '?';
        $resolved = @gethostbyname($host);

        if ($resolved === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return new Result(self::CAT, 'Nom d\'hote', Result::WARN, $host,
                'Le nom de machine ne se resout pas en adresse IP. Certains outils et '
                . 'les emails de test (MailHog) en souffre.',
                'Ajoutez une ligne "192.168.x.x  ' . $host . '" dans C:\\Windows\\System32\\drivers\\etc\\hosts.',
                ['resolved' => $resolved]);
        }

        return new Result(self::CAT, 'Nom d\'hote', Result::OK, $host . ' -> ' . $resolved,
            'Resolution locale Operationnelle.');
    }

    private static function dns(): Result
    {
        $t = microtime(true);
        $ip = @gethostbyname('packagist.org');
        $ms = (int) round((microtime(true) - $t) * 1000);

        if ($ip === 'packagist.org' || $ms > 5000) {
            return new Result(self::CAT, 'Resolution DNS', Result::WARN,
                $ms > 5000 ? 'timeout' : 'echec',
                'La resolution de noms externes ne repond pas. Composer, npm et git ne pourront '
                . 'pas telecharger de dependances.',
                'Verifiez la connexion Internet, le serveur DNS, et les variables d\'environnement proxy.');
        }

        return new Result(self::CAT, 'Resolution DNS', Result::OK, $ip,
            'DNS externe fonctionnel en ' . $ms . ' ms.');
    }

    /** @param array<string,mixed> $net */
    private static function outbound(array $net): Result
    {
        $url = (string) $net['outbound_url'];
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'HEAD',
                'timeout'       => 6,
                'ignore_errors' => true,
                'header'        => "User-Agent: DevCheck/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $t = microtime(true);
        $body = @file_get_contents($url, false, $ctx);
        $ms = (int) round((microtime(true) - $t) * 1000);

        if ($body === false) {
            $err = error_get_last()['message'] ?? 'raison inconnue';
            return new Result(self::CAT, 'Acces sortant HTTPS', Result::WARN, 'echec',
                'Impossible de joindre ' . $url . ' (' . preg_replace('/\s+/', ' ', $err) . ').',
                'Hors ligne, derriere un proxy, ou avec un antivirus bloquant SSL. '
                . 'Les dependances distantes ne se telechargeront pas.');
        }

        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }

        $ok = $code >= 200 && $code < 400;

        return new Result(self::CAT, 'Acces sortant HTTPS', $ok ? Result::OK : Result::WARN,
            'HTTP ' . $code . ' en ' . $ms . ' ms',
            $ok
                ? 'Sortie Internet operationnelle : registries, API et telechargements disponibles.'
                : 'Reponse inattendue du registre distant (HTTP ' . $code . ').');
    }
}
