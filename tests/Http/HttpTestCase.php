<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

use Aura\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests de bout en bout par HTTP (suite « http ») : un vrai serveur PHP (php -S, même routeur que
 * la production : public/index.php) sert l'application sur la base du CI, et chaque test enchaîne de
 * vraies requêtes (session et cookie, jeton CSRF, POST, redirections). Ils remplacent le smoke shell
 * local et couvrent les contrôleurs tels qu'un navigateur les appelle.
 *
 * Ignorés sans DB_HOST / DB_NAME (comme les tests de DAO). Les écritures portent sur des données de
 * test aux identifiants improbables (ZZ…) et sont nettoyées par chaque test : la base du CI est
 * partagée par toute la suite, il n'y a pas de transaction à annuler côté serveur.
 */
abstract class HttpTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private string $cookieJar = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::databaseConfigured()) {
            return;
        }
        // Port libre choisi par le système, pour lancer la suite en parallèle d'un serveur de dev.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new RuntimeException('Aucun port libre pour le serveur de test.');
        }
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $root = dirname(__DIR__, 2);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $root,
            getenv()
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('Le serveur de test ne répond pas.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (!self::databaseConfigured()) {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test HTTP ignoré (hors CI).');
        }
        // Même fuseau que l'application (public/index.php) pour les heures écrites par les tests.
        date_default_timezone_set('Europe/Paris');
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'aura_http_');
    }

    protected function tearDown(): void
    {
        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /** Connexion à la base du serveur de test, pour préparer et nettoyer les données. */
    protected function pdo(): PDO
    {
        return Database::get();
    }

    protected function get(string $path): HttpResponse
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, mixed> $fields formulaire classique (application/x-www-form-urlencoded) */
    protected function postForm(string $path, array $fields, array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, http_build_query($fields), $headers);
    }

    /** @param array<string, mixed> $body corps JSON, comme public/js/*.js */
    protected function postJson(string $path, array $body, ?string $csrf = null): HttpResponse
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }

        return $this->request('POST', $path, (string) json_encode($body), $headers);
    }

    /** Jeton CSRF d'une page (attribut data-csrf ou champ caché csrf), lié à la session du test. */
    protected function csrfFrom(HttpResponse $page): string
    {
        if (preg_match('/(?:data-csrf|name="csrf" value)="([0-9a-f]{16,})"/', $page->body, $m) !== 1) {
            self::fail('Aucun jeton CSRF dans la page.');
        }

        return $m[1];
    }

    /** @param list<string> $headers */
    private function request(string $method, string $path, ?string $body = null, array $headers = []): HttpResponse
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . $path);
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $content = curl_exec($curl);
        if ($content === false) {
            self::fail('Requête impossible : ' . curl_error($curl));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $content, $responseHeaders);
    }

    private static function databaseConfigured(): bool
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');

        return $host !== false && $host !== '' && $name !== false && $name !== '';
    }
}
